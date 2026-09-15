<?php
/**
 * ICICI Payment Gateway (Orange PG) — server-side helpers.
 *
 * Handles the three things the portal needs from the gateway:
 *   1. Building the initiateSale request and posting it
 *   2. Verifying the hash on the return callback
 *   3. Checking the status of a transaction
 *
 * Every constant this file reads (PG_MERCHANT_ID, PG_KEY, etc.) is defined
 * in admin/config.php and can be overridden in config.local.php for production.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/* ---------- txn number ---------- */

/**
 * A merchant transaction number that is unique and traceable.
 *
 * Format: MF-{appId}-{stage}-{timestamp}{random}
 * The gateway requires this to be unique across all calls.
 */
function pg_generate_txn_no(int $appId, string $stage): string
{
    return 'MF-' . $appId . '-' . $stage . '-' . date('YmdHis') . bin2hex(random_bytes(3));
}

/* ---------- secure hash ---------- */

/**
 * The HMAC-SHA256 hash the gateway expects on every request.
 *
 * The hash text is the concatenation of the parameter VALUES (not keys) in
 * the fixed alphabetical order of the parameter NAMES documented in the spec.
 * The key is PG_KEY.
 *
 * Key order (from the documentation):
 *   addlParam1 addlParam2 aggregatorID amount currencyCode customerEmailID
 *   customerMobileNo customerName merchantId merchantTxnNo payType returnURL
 *   transactionType txnDate
 */
function pg_secure_hash(array $params): string
{
    $keyOrder = [
        'addlParam1', 'addlParam2', 'aggregatorID', 'amount', 'currencyCode',
        'customerEmailID', 'customerMobileNo', 'customerName', 'merchantId',
        'merchantTxnNo', 'payType', 'returnURL', 'transactionType', 'txnDate',
    ];

    $hashText = '';

    foreach ($keyOrder as $key) {
        $hashText .= (string) ($params[$key] ?? '');
    }

    return hash_hmac('sha256', $hashText, PG_KEY);
}

/**
 * Hash for the status-check / refund command API.
 *
 * Key order: aggregatorID merchantId merchantTxnNo originalTxnNo transactionType
 */
function pg_command_hash(array $params): string
{
    $keyOrder = [
        'aggregatorID', 'merchantId', 'merchantTxnNo',
        'originalTxnNo', 'transactionType',
    ];

    $hashText = '';

    foreach ($keyOrder as $key) {
        $hashText .= (string) ($params[$key] ?? '');
    }

    return hash_hmac('sha256', $hashText, PG_KEY);
}

/* ---------- initiate sale ---------- */

/**
 * Calls the initiateSale API and returns the parsed response, or null on
 * a transport failure.
 *
 * On success the response contains:
 *   responseCode  — R1000 means redirect the browser
 *   redirectURI   — the base URL to redirect to
 *   tranCtx       — appended as ?tranCtx= on the redirect
 */
function pg_initiate_sale(array $app, string $stage, float $amount): ?array
{
    $returnUrl = base_url() . '/portal/pg-return.php';

    $merchantTxnNo = pg_generate_txn_no((int) $app['id'], $stage);

    /* the mobile number the gateway sees: digits only, no dial code prefix,
       because Indian numbers are what this merchant is set up for */
    $mobile = preg_replace('/\D/', '', (string) ($app['mobile_number'] ?? ''));
    if (strlen($mobile) > 10) {
        $mobile = substr($mobile, -10);
    }

    $params = [
        'merchantId'      => PG_MERCHANT_ID,
        'aggregatorID'    => PG_AGGREGATOR_ID,
        'merchantTxnNo'   => $merchantTxnNo,
        'amount'          => number_format($amount, 2, '.', ''),
        'currencyCode'    => PG_CURRENCY_CODE,
        'payType'         => PG_PAY_TYPE,
        'customerEmailID' => (string) ($app['email'] ?? ''),
        'transactionType' => 'SALE',
        'returnURL'       => $returnUrl,
        'txnDate'         => date('YmdHis'),
        'customerMobileNo' => $mobile,
        'customerName'    => mb_substr((string) ($app['full_name'] ?? 'Customer'), 0, 60),
        'addlParam1'      => (string) ($app['reference_code'] ?? ''),
        'addlParam2'      => $stage,
    ];

    $params['secureHash'] = pg_secure_hash($params);

    $json = json_encode($params, JSON_UNESCAPED_SLASHES);

    /* store the initiated transaction before calling the gateway, so we have
       a record even if the cURL call never comes back */
    db()->prepare(
        'INSERT INTO pg_transactions (application_id, stage, merchant_txn_no, amount)
         VALUES (?, ?, ?, ?)'
    )->execute([(int) $app['id'], $stage, $merchantTxnNo, $amount]);

    $ch = curl_init(PG_INITIATE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        error_log('PG initiateSale cURL error: ' . $err);
        return null;
    }

    $response = json_decode((string) $body, true);

    if (!is_array($response)) {
        error_log('PG initiateSale non-JSON response: ' . mb_substr((string) $body, 0, 500));
        return null;
    }

    /* save the tranCtx so the return handler can cross-check */
    if (!empty($response['tranCtx'])) {
        db()->prepare(
            'UPDATE pg_transactions SET tran_ctx = ? WHERE merchant_txn_no = ?'
        )->execute([$response['tranCtx'], $merchantTxnNo]);
    }

    $response['_merchantTxnNo'] = $merchantTxnNo;

    return $response;
}

/* ---------- verify return hash ---------- */

/**
 * Whether the hash on a gateway return callback is valid.
 *
 * The return carries a secureHash over the same scheme as the request leg: the
 * parameter VALUES concatenated in byte order of the parameter NAMES,
 * HMAC-SHA256 under PG_KEY. The spec spells that order out for the request and
 * not for the return, so it is reconstructed here from whichever parameters
 * actually arrived — checked against a real UAT success return, where it
 * reproduces the gateway's hash exactly. Byte order, not case-insensitive
 * order: `TransmissionDateTime` sorts before the lowercase names, and folding
 * case breaks the hash.
 *
 * The parameter is named `secureHash` in practice and `securehash` in the
 * written spec, so it is matched either way.
 *
 * A false here still means "not proven" rather than "proven forged" — a gateway
 * that adds a field we mishandle would fail this too. Callers use it to accept
 * a result, never to reject one outright; the status-check API is the authority.
 */
function pg_verify_return(array $data): bool
{
    $claimed = '';

    foreach ($data as $key => $value) {
        if (strcasecmp((string) $key, 'securehash') === 0) {
            $claimed = trim((string) $value);
        }
    }

    if ($claimed === '') {
        return false;
    }

    $signed = [];

    foreach ($data as $key => $value) {
        if (strcasecmp((string) $key, 'securehash') !== 0 && !is_array($value)) {
            $signed[(string) $key] = (string) $value;
        }
    }

    ksort($signed, SORT_STRING);

    $expected = hash_hmac('sha256', implode('', $signed), PG_KEY);

    return hash_equals($expected, strtolower($claimed));
}

/* ---------- status check ---------- */

/**
 * Calls the status-check command API and returns the parsed response.
 */
function pg_check_status(string $merchantTxnNo): ?array
{
    $params = [
        'merchantId'      => PG_MERCHANT_ID,
        'aggregatorID'    => PG_AGGREGATOR_ID,
        'merchantTxnNo'   => $merchantTxnNo,
        'transactionType' => 'STATUS',
        'originalTxnNo'   => $merchantTxnNo,
    ];

    $params['secureHash'] = pg_command_hash($params);

    $json = json_encode($params, JSON_UNESCAPED_SLASHES);

    $ch = curl_init(PG_COMMAND_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err !== '') {
        error_log('PG status check cURL error: ' . $err);
        return null;
    }

    $response = json_decode((string) $body, true);

    if (!is_array($response)) {
        error_log('PG status check non-JSON: ' . mb_substr((string) $body, 0, 500));
        return null;
    }

    return $response;
}

/**
 * Whether a response code from the gateway means the payment succeeded.
 *
 * The documentation lists 0000 and 000 as success codes.
 */
function pg_is_success(string $code): bool
{
    return in_array(trim($code), ['0000', '000'], true);
}
