<?php
/**
 * Dealer portal — session and the guard that keeps a dealer inside their own data.
 *
 * Everything here reads. A dealer sees what they have sold and what they have
 * been paid; nothing in this folder writes to an application, a payment or a
 * payout. The office does that from the admin.
 *
 * Sign-in is the same one-time code the applicant portal uses, asked for with
 * the `dealer` audience so an address only opens this portal when it belongs to
 * an active dealer.
 */

declare(strict_types=1);

require_once __DIR__ . '/../portal/lib.php';

/** The signed-in dealer's row, or null. */
function dealer_user(): ?array
{
    $id = (int) ($_SESSION['dealer_id'] ?? 0);

    if ($id === 0) {
        return null;
    }

    $dealer = dealer_by_id($id);

    /* switched off while they were signed in: the session stops meaning anything */
    if (!$dealer || !$dealer['is_active']) {
        unset($_SESSION['dealer_id']);

        return null;
    }

    return $dealer;
}

/** The signed-in dealer, or the sign-in page. */
function require_dealer(): array
{
    $dealer = dealer_user();

    if (!$dealer) {
        header('Location: login.php');
        exit;
    }

    return $dealer;
}

/**
 * What a dealer is allowed to know about one of their customers.
 *
 * Deliberately not the whole row. A dealer introduced this person; they did not
 * become their bank. Payment proofs, receipts, UTR numbers, identity documents
 * and the customer's address never leave the admin — the dealer sees who it is,
 * where the sale has got to, and what it is worth to them.
 */
function dealer_client_view(array $client): array
{
    return [
        'reference_code'    => (string) $client['reference_code'],
        'full_name'         => (string) $client['full_name'],
        'email'             => (string) $client['email'],
        'mobile_number'     => (string) ($client['mobile_number'] ?? ''),
        'product'           => (string) $client['product'],
        'status'            => (string) $client['status'],
        'created_at'        => (string) $client['created_at'],
        'dealer_commission' => (float) $client['dealer_commission'],
        /* the only thing that decides whether the commission counts */
        'earned'            => !empty($client['booking_paid_at']) && $client['status'] !== 'rejected',
    ];
}

/** Their customers, each one trimmed to what they may see. */
function dealer_own_clients(int $dealerId): array
{
    return array_map('dealer_client_view', dealer_clients($dealerId));
}

/**
 * How far along a sale is, as a dealer reads it: the stage, and how many of the
 * five it is. No amounts outstanding, no receipts — just progress.
 */
function dealer_progress(string $status): array
{
    if ($status === 'rejected') {
        return ['label' => 'Not proceeding', 'step' => 0, 'of' => count(APPLICATION_STAGES)];
    }

    $step = array_search($status, APPLICATION_STAGES, true);

    return [
        'label' => status_label($status, 'applicant'),
        'step'  => $step === false ? 0 : (int) $step + 1,
        'of'    => count(APPLICATION_STAGES),
    ];
}
