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
    /* Asked several times over one request — the sign-in page alone calls
       portal_roles() twice, and the chrome asks again — so the row is looked
       up once and held for the rest of the request. Not the session: a dealer
       switched off mid-session has to stop counting as one on the next page,
       which is the whole reason this is a query and not a session flag. */
    static $cached = null;

    $id = (int) ($_SESSION['dealer_id'] ?? 0);

    if ($id === 0) {
        return null;
    }

    if ($cached !== null && (int) $cached['id'] === $id) {
        return $cached;
    }

    $dealer = dealer_by_id($id);

    /* Switched off — or turned down, or still waiting — while they were signed
       in: the session stops meaning anything. A dealer without the office's yes
       has no code, so there is nothing for this panel to show them. */
    if (!$dealer || !$dealer['is_active'] || $dealer['approval_status'] !== 'approved') {
        unset($_SESSION['dealer_id']);

        return null;
    }

    $cached = $dealer;

    return $dealer;
}

/** The signed-in dealer, or the sign-in page. */
function require_dealer(): array
{
    $dealer = dealer_user();

    if (!$dealer) {
        /* one sign-in for everybody: the address decides the role */
        header('Location: ../portal/');
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
        /* one definition of earned, shared with the admin and the distributor */
        'earned'            => commission_is_earned($client),
    ];
}

/** Their customers, each one trimmed to what they may see. */
function dealer_own_clients(int $dealerId): array
{
    return array_map('dealer_client_view', dealer_clients($dealerId));
}
