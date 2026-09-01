<?php
/**
 * Distributor portal — session and the guard that keeps a distributor inside
 * their own data.
 *
 * Everything here reads. A distributor sees their dealers, what has been sold
 * under them and what they have been paid; nothing in this folder writes to an
 * application, a payment or a payout. The office does that from the admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

/** The signed-in distributor's row, or null. */
function distributor_user(): ?array
{
    $id = (int) ($_SESSION['distributor_id'] ?? 0);

    if ($id === 0) {
        return null;
    }

    $dist = distributor_by_id($id);

    /* switched off while they were signed in: the session stops meaning anything */
    if (!$dist || !$dist['is_active']) {
        unset($_SESSION['distributor_id']);

        return null;
    }

    return $dist;
}

/** The signed-in distributor, or the sign-in page. */
function require_distributor(): array
{
    $dist = distributor_user();

    if (!$dist) {
        /* one sign-in for everybody: the address decides the role */
        header('Location: ../portal/');
        exit;
    }

    return $dist;
}

/**
 * What a distributor is allowed to know about a sale under them.
 *
 * An allow-list, not a filter: the row is rebuilt field by field so a column
 * added later cannot leak by default. Payment proofs, receipts, UTR references,
 * identity documents and the customer's address stay in the admin. They see who
 * it is, who sold it, how far along it is, and what it is worth to them.
 */
function distributor_client_view(array $client): array
{
    return [
        'reference_code'         => (string) $client['reference_code'],
        'full_name'              => (string) $client['full_name'],
        'email'                  => (string) $client['email'],
        'mobile_number'          => (string) ($client['mobile_number'] ?? ''),
        'product'                => (string) $client['product'],
        'status'                 => (string) $client['status'],
        'created_at'             => (string) $client['created_at'],
        'dealer_name'            => (string) ($client['dealer_name'] ?? ''),
        'dealer_code'            => (string) ($client['dealer_code'] ?? ''),
        'distributor_commission' => (float) $client['distributor_commission'],
        /* one definition of earned, shared with the admin and the dealer portal */
        'earned'                 => commission_is_earned($client),
    ];
}

/** Their sales, each one trimmed to what they may see. */
function distributor_own_clients(int $distributorId): array
{
    return array_map('distributor_client_view', distributor_clients($distributorId));
}

/**
 * One of their dealers, trimmed the same way.
 *
 * A distributor may see how their dealers are doing. They may not see a
 * dealer's bank details, PAN or what the office owes them personally.
 */
function distributor_dealer_view(array $dealer, int $distributorId): array
{
    $totals   = dealer_totals((int) $dealer['id']);
    $override = distributor_override_from_dealer($distributorId, (int) $dealer['id']);

    return [
        'id'            => (int) $dealer['id'],
        'full_name'     => (string) $dealer['full_name'],
        'company'       => (string) ($dealer['company'] ?? ''),
        'dealer_code'   => (string) $dealer['dealer_code'],
        'email'         => (string) ($dealer['email'] ?? ''),
        'mobile_number' => (string) ($dealer['mobile_number'] ?? ''),
        'city'          => (string) ($dealer['city'] ?? ''),
        'is_active'     => (bool) $dealer['is_active'],
        /* a dealer they asked for but the office has not decided on yet */
        'approval_status' => (string) $dealer['approval_status'],
        'sales'         => $totals['sales'],
        'confirmed'     => $totals['confirmed'],
        /* what this dealer has earned the distributor, which is the distributor's
           own money and so theirs to see — unlike what the dealer is owed */
        'override'      => $override['earned'],
        'pipeline'      => $override['pipeline'],
    ];
}

/** The dealers under them, each trimmed to what they may see. */
function distributor_own_dealers(int $distributorId): array
{
    return array_map(
        static fn (array $dealer): array => distributor_dealer_view($dealer, $distributorId),
        distributor_dealers($distributorId)
    );
}
