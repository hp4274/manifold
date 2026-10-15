<?php
/**
 * Principal / Zonal C&F desk — session guard.
 *
 * Signed in through the one portal sign-in (portal/), which sets
 * principal_cf_id or zonal_cf_id. The tier sees its network and its own
 * commission, and adds and edits the partners beneath it; the office does the rest of what writes.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

/**
 * [tier, row] for the signed-in C&F, or null. $_GET['as'] picks when one
 * address holds both tiers; the row is re-read so a switched-off partner is out.
 */
function cf_partner(): ?array
{
    $want  = (string) ($_GET['as'] ?? '');
    $tiers = array_values(array_filter(['principal_cf', 'zonal_cf'], static fn ($t) => !empty($_SESSION[$t . '_id'])));
    $tier  = in_array($want, $tiers, true) ? $want : ($tiers[0] ?? '');

    if ($tier === '') {
        return null;
    }

    $row = $tier === 'principal_cf'
        ? principal_cf_by_id((int) $_SESSION['principal_cf_id'])
        : zonal_cf_by_id((int) $_SESSION['zonal_cf_id']);

    if (!$row || !$row['is_active'] || $row['approval_status'] !== 'approved') {
        unset($_SESSION[$tier . '_id']);

        return null;
    }

    return [$tier, $row];
}

function require_cf_partner(): array
{
    $p = cf_partner();

    if (!$p) {
        guard_redirect('../portal/');
    }

    return $p;
}

/** Zonal C&Fs under a principal (empty for a zonal). */
function cf_zonals(string $tier, array $me): array
{
    return $tier === 'principal_cf' ? zonal_cf_for_principal((int) $me['id']) : [];
}

/** Distributors under this partner: a zonal's own, or all under a principal's zonals. */
function cf_distributors(string $tier, array $me): array
{
    $stmt = db()->prepare(
        $tier === 'principal_cf'
            ? 'SELECT d.*, z.full_name AS zonal_name FROM distributors d
                 JOIN zonal_cf z ON z.id = d.zonal_cf_id
                WHERE z.principal_cf_id = ? ORDER BY d.is_active DESC, d.full_name'
            : 'SELECT d.*, NULL AS zonal_name FROM distributors d
                WHERE d.zonal_cf_id = ? ORDER BY d.is_active DESC, d.full_name'
    );
    $stmt->execute([(int) $me['id']]);

    return $stmt->fetchAll();
}

/** The rate this partner earns themselves: the ceiling for anyone below them. */
function cf_own_rate(string $tier, array $me): float
{
    return commission_rate($tier, $tier === 'zonal_cf' ? (int) $me['id'] : null);
}

/**
 * One partner under this C&F, fetched by id *and* by ownership, so a guessed id
 * opens nobody else's. $kind is 'zonal_cf' or 'distributor'.
 */
function cf_owned(string $tier, array $me, string $kind, int $id): ?array
{
    foreach ($kind === 'zonal_cf' ? cf_zonals($tier, $me) : cf_distributors($tier, $me) as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }

    return null;
}

/** [value|null, error]: blank means "use the standard rate". */
function cf_rate_parse(string $raw, float $ceiling): array
{
    $raw = str_replace(['%', ','], '', trim($raw));

    if ($raw === '') {
        return [null, ''];
    }

    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > $ceiling) {
        return [null, 'The rate has to be between 0 and your own ' . rate_short($ceiling) . '.'];
    }

    return [number_format((float) $raw, 2, '.', ''), ''];
}

/** Flash across a redirect, then back to a desk page. */
function cf_done(string $message, string $to, string $tier): void
{
    $_SESSION['cfdesk_flash'] = $message;
    header('Location: ' . $to . '?as=' . $tier);
    exit;
}

function cf_flash(): string
{
    $f = (string) ($_SESSION['cfdesk_flash'] ?? '');
    unset($_SESSION['cfdesk_flash']);

    return $f;
}

/** Status pill text + class for a partner row. */
function cf_status(array $row): array
{
    if (($row['approval_status'] ?? 'approved') === 'pending') {
        return ['With the office', 'pill--pending'];
    }

    if (($row['approval_status'] ?? '') === 'rejected') {
        return ['Turned down', 'pill--rejected'];
    }

    return $row['is_active'] ? ['Active', 'pill--accepted'] : ['Switched off', 'pill--refunded'];
}
