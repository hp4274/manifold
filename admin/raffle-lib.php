<?php
/**
 * The gold raffle: the cycle, and the winners the office picks.
 *
 * Nothing is drawn automatically. The office holds the draw however it likes —
 * in front of witnesses, as the promotion says — and then records who won by
 * searching for each person here by name, reference code or mobile number.
 *
 * All this file works out on its own is the calendar: one date is set, and every
 * following reveal is that date plus a whole number of cycles. A draw row exists
 * for each cycle so winners have something to hang off, and the website shows a
 * list once its reveal time has passed.
 *
 * raffle_sync() is called by both the admin page and the public feed and creates
 * whatever rows the clock says should exist. It never picks anybody.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/** Draws are only ever created this far ahead of the first one. */
const RAFFLE_MAX_CYCLES = 80;

/**
 * Everything the office can change, already typed.
 */
function raffle_config(bool $reload = false): array
{
    static $config = null;

    if ($config !== null && !$reload) {
        return $config;
    }

    settings_all($reload);

    $first = trim(setting('raffle_first_draw', ''));

    return $config = [
        'enabled'      => setting('raffle_enabled', '1') === '1',
        'first_draw'   => $first,
        'cycle_days'   => max(1, (int) setting('raffle_cycle_days', '90')),
        'winner_count' => max(1, min(50, (int) setting('raffle_winner_count', '5'))),
        'gold_grams'   => max(0.001, (float) setting('raffle_gold_grams', '1')),
        'gold_rate'    => max(0.0, (float) setting('raffle_gold_rate', '7000')),
        'discount_min' => max(0.0, (float) setting('raffle_cash_discount_min', '5')),
        'discount_max' => max(0.0, (float) setting('raffle_cash_discount_max', '7')),
    ];
}

/** True once the office has said when the first draw happens. */
function raffle_running(): bool
{
    $config = raffle_config();

    return $config['enabled'] && $config['first_draw'] !== '';
}

/** When draw number $no is revealed. Draw 1 is the date the office set. */
function raffle_reveal_at(int $no): ?DateTimeImmutable
{
    $config = raffle_config();

    if ($config['first_draw'] === '') {
        return null;
    }

    try {
        $first = new DateTimeImmutable($config['first_draw']);
    } catch (Exception $e) {
        return null;
    }

    if ($no <= 1) {
        return $first;
    }

    return $first->add(new DateInterval('P' . (($no - 1) * $config['cycle_days']) . 'D'));
}

/**
 * The draw number the site is counting down to: the first one whose reveal
 * still lies in the future. Null when the raffle is not running.
 */
function raffle_next_no(): ?int
{
    $config = raffle_config();
    $first  = raffle_reveal_at(1);

    if ($first === null) {
        return null;
    }

    $now = new DateTimeImmutable('now');

    if ($first > $now) {
        return 1;
    }

    /* whole cycles gone by since the first reveal */
    $elapsed = $now->getTimestamp() - $first->getTimestamp();
    $cycle   = $config['cycle_days'] * 86400;

    return min(RAFFLE_MAX_CYCLES, intdiv($elapsed, $cycle) + 2);
}

/** How much cash a winner is offered instead of the coin: [low, high]. */
function raffle_cash_range(?float $grams = null, ?float $rate = null): array
{
    $config = raffle_config();
    $value  = ($grams ?? $config['gold_grams']) * ($rate ?? $config['gold_rate']);

    $low  = $value * (1 - max($config['discount_min'], $config['discount_max']) / 100);
    $high = $value * (1 - min($config['discount_min'], $config['discount_max']) / 100);

    return [round($low, 2), round($high, 2)];
}

/* --------------------------------------------------------------------------
 * Draws
 * ----------------------------------------------------------------------- */

function raffle_draw_by_no(int $no): ?array
{
    $stmt = db()->prepare('SELECT * FROM raffle_draws WHERE draw_no = ?');
    $stmt->execute([$no]);

    return $stmt->fetch() ?: null;
}

function raffle_draw_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM raffle_draws WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->fetch() ?: null;
}

/** Has this draw gone public? */
function raffle_is_revealed(array $draw): bool
{
    return strtotime((string) $draw['reveal_at']) <= time();
}

/**
 * Create whatever draw rows the calendar says should exist by now. Cheap and
 * idempotent — safe to call on every request. Picks nobody.
 */
function raffle_sync(): void
{
    if (!raffle_running()) {
        return;
    }

    $config = raffle_config();
    $next   = raffle_next_no();

    if ($next === null) {
        return;
    }

    /* every draw up to and including the one being counted down to */
    for ($no = 1; $no <= $next; $no++) {
        $when = raffle_reveal_at($no);

        if ($when === null) {
            break;
        }

        if (!raffle_draw_by_no($no)) {
            db()->prepare(
                'INSERT INTO raffle_draws (draw_no, reveal_at, winner_count, gold_grams, gold_rate)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $no,
                $when->format('Y-m-d H:i:s'),
                $config['winner_count'],
                $config['gold_grams'],
                $config['gold_rate'],
            ]);
        }
    }

    /* a reveal date the office moved leaves old rows on the wrong day. One
       already public keeps the date it was published on. */
    $rows = db()->query('SELECT id, draw_no, reveal_at FROM raffle_draws ORDER BY draw_no')->fetchAll();

    foreach ($rows as $row) {
        $should = raffle_reveal_at((int) $row['draw_no']);

        if ($should !== null
            && strtotime((string) $row['reveal_at']) > time()
            && $should->format('Y-m-d H:i:s') !== date('Y-m-d H:i:s', strtotime((string) $row['reveal_at']))) {
            db()->prepare('UPDATE raffle_draws SET reveal_at = ? WHERE id = ?')
                ->execute([$should->format('Y-m-d H:i:s'), (int) $row['id']]);
        }
    }
}

/* --------------------------------------------------------------------------
 * Who may win, and finding them
 * ----------------------------------------------------------------------- */

/**
 * Applicants the promotion allows: paid in full. Used for the count on the
 * page and to decide what the search may offer.
 */
function raffle_eligible_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM applications WHERE status = 'complete'")->fetchColumn();
}

/**
 * Find someone to record as a winner. One box, three ways in: any part of the
 * name, the reference code, or the mobile number — typed with spaces, dashes or
 * a country code, since that is how they are written down.
 *
 * Only applicants who have paid in full are ever returned; that is the rule the
 * promotion is run on. Each row says whether they already hold a place.
 */
function raffle_search(string $query, int $drawId = 0, int $limit = 12): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $limit  = max(1, min($limit, 50));
    $like   = '%' . $query . '%';
    $digits = preg_replace('/\D+/', '', $query);

    /* a mobile typed with a country code still has to match the stored number */
    $tail = $digits !== '' && strlen($digits) > 4 ? '%' . substr($digits, -8) : null;

    $sql = "SELECT a.id, a.full_name, a.email, a.mobile_number, a.city, a.state,
                   a.reference_code, a.referral_code, a.product, a.completed_at,
                   w.id AS winner_id, w.draw_id AS won_draw_id, d.draw_no AS won_draw_no
              FROM applications a
         LEFT JOIN raffle_winners w ON w.application_id = a.id
         LEFT JOIN raffle_draws d   ON d.id = w.draw_id
             WHERE a.status = 'complete'
               AND (a.full_name LIKE ? OR a.reference_code LIKE ? OR a.referral_code LIKE ?
                    OR REPLACE(REPLACE(a.mobile_number, ' ', ''), '-', '') LIKE ?"
             . ($tail === null ? '' : ' OR REPLACE(REPLACE(a.mobile_number, \' \', \'\'), \'-\', \'\') LIKE ?')
             . ")
          ORDER BY a.full_name
             LIMIT " . $limit;

    $params = [$like, $like, $like, '%' . ($digits !== '' ? $digits : $query) . '%'];

    if ($tail !== null) {
        $params[] = $tail;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $rows = [];

    /* the join can return a person once per place they hold; fold them together */
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['id'];

        if (!isset($rows[$id])) {
            $row['in_this_draw'] = false;
            $row['won_draws']    = [];
            $rows[$id] = $row;
        }

        if ($row['won_draw_no'] !== null) {
            $rows[$id]['won_draws'][] = (int) $row['won_draw_no'];

            if ($drawId > 0 && (int) $row['won_draw_id'] === $drawId) {
                $rows[$id]['in_this_draw'] = true;
            }
        }
    }

    return array_values($rows);
}

/**
 * Record a winner against a draw. Returns an empty string on success, otherwise
 * why not.
 */
function raffle_add_winner(int $drawId, int $applicationId, ?int $userId = null): string
{
    $draw = raffle_draw_by_id($drawId);

    if (!$draw) {
        return 'That draw no longer exists.';
    }

    $app = db()->prepare('SELECT id, full_name, status FROM applications WHERE id = ?');
    $app->execute([$applicationId]);
    $person = $app->fetch();

    if (!$person) {
        return 'That applicant no longer exists.';
    }

    if ($person['status'] !== 'complete') {
        return $person['full_name'] . ' has not paid in full, so cannot be entered.';
    }

    $already = db()->prepare('SELECT COUNT(*) FROM raffle_winners WHERE draw_id = ? AND application_id = ?');
    $already->execute([$drawId, $applicationId]);

    if ((int) $already->fetchColumn() > 0) {
        return $person['full_name'] . ' is already on this list.';
    }

    $held = db()->prepare('SELECT COUNT(*) FROM raffle_winners WHERE draw_id = ?');
    $held->execute([$drawId]);
    $taken = (int) $held->fetchColumn();

    if ($taken >= (int) $draw['winner_count']) {
        return 'Draw ' . (int) $draw['draw_no'] . ' already has its '
            . (int) $draw['winner_count'] . ' winners. Remove one first, or raise the number in setup.';
    }

    /* the lowest place nobody holds, so removing #2 and adding again fills #2 */
    $used = db()->prepare('SELECT position FROM raffle_winners WHERE draw_id = ?');
    $used->execute([$drawId]);
    $positions = array_map('intval', $used->fetchAll(PDO::FETCH_COLUMN));

    $position = 1;

    while (in_array($position, $positions, true)) {
        $position++;
    }

    db()->prepare('INSERT INTO raffle_winners (draw_id, application_id, position) VALUES (?, ?, ?)')
        ->execute([$drawId, $applicationId, $position]);

    if ($userId !== null) {
        log_status_change('raffle_winner', (int) db()->lastInsertId(), null, 'added', $userId);
    }

    return '';
}

/** Take somebody off a list. */
function raffle_remove_winner(int $winnerId, ?int $userId = null): string
{
    $stmt = db()->prepare('SELECT id FROM raffle_winners WHERE id = ?');
    $stmt->execute([$winnerId]);

    if (!$stmt->fetchColumn()) {
        return 'That winner is already gone.';
    }

    db()->prepare('DELETE FROM raffle_winners WHERE id = ?')->execute([$winnerId]);

    if ($userId !== null) {
        log_status_change('raffle_winner', $winnerId, 'added', 'removed', $userId);
    }

    return '';
}

/** The winners of one draw, with the person behind each. */
function raffle_winners_for(int $drawId): array
{
    $stmt = db()->prepare(
        'SELECT w.id, w.draw_id, w.position, w.created_at,
                a.id AS application_id, a.full_name, a.email, a.mobile_number,
                a.city, a.state, a.reference_code, a.product
           FROM raffle_winners w
           JOIN applications a ON a.id = w.application_id
          WHERE w.draw_id = ?
       ORDER BY w.position'
    );
    $stmt->execute([$drawId]);

    return $stmt->fetchAll();
}

/** Draws that have already gone public, newest first. */
function raffle_revealed_draws(int $limit = 8): array
{
    $limit = max(1, min($limit, 40));

    return db()->query(
        'SELECT * FROM raffle_draws
          WHERE reveal_at <= NOW()
       ORDER BY draw_no DESC
          LIMIT ' . $limit
    )->fetchAll();
}

function raffle_all_draws(int $limit = 40): array
{
    $limit = max(1, min($limit, 80));

    return db()->query('SELECT * FROM raffle_draws ORDER BY draw_no DESC LIMIT ' . $limit)->fetchAll();
}

/* --------------------------------------------------------------------------
 * What the public is allowed to see
 * ----------------------------------------------------------------------- */

/** "Harsh Patel" becomes "Harsh P." */
function raffle_mask_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static function ($part) { return $part !== ''; }));

    if (!$parts) {
        return 'A winner';
    }

    if (count($parts) === 1) {
        return $parts[0];
    }

    $last = (string) $parts[count($parts) - 1];

    return $parts[0] . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
}

/** "+91 98765 43210" becomes "98******10" — enough for its owner to know. */
function raffle_mask_mobile(string $mobile): string
{
    $digits = preg_replace('/\D+/', '', $mobile);
    $digits = $digits === null ? '' : $digits;

    /* a number given with its country code: mask the number itself */
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    if (strlen($digits) < 5) {
        return str_repeat('*', max(4, strlen($digits)));
    }

    return substr($digits, 0, 2) . str_repeat('*', strlen($digits) - 4) . substr($digits, -2);
}

/** One winner, stripped down to what may leave the building. */
function raffle_public_winner(array $winner): array
{
    return [
        'name'   => raffle_mask_name((string) $winner['full_name']),
        'mobile' => raffle_mask_mobile((string) $winner['mobile_number']),
        'city'   => (string) ($winner['city'] ?: ''),
    ];
}

/**
 * Everything raffle.php hands to the popup. Only revealed draws are in here —
 * a list the office is still putting together never reaches this function.
 */
function raffle_public_payload(int $history = 4): array
{
    $config = raffle_config();

    $payload = [
        'enabled'     => $config['enabled'],
        'running'     => raffle_running(),
        'cycleDays'   => $config['cycle_days'],
        'winnerCount' => $config['winner_count'],
        'goldGrams'   => $config['gold_grams'],
        'goldRate'    => $config['gold_rate'],
        'currency'    => PAYMENT_CURRENCY,
        'discount'    => [
            'min' => $config['discount_min'],
            'max' => $config['discount_max'],
        ],
        'nextDraw'    => null,
        'draws'       => [],
        'poolSize'    => 0,
    ];

    if (!$payload['enabled']) {
        return $payload;
    }

    [$low, $high] = raffle_cash_range();

    $payload['cashRange'] = ['low' => $low, 'high' => $high];

    if (!$payload['running']) {
        return $payload;
    }

    raffle_sync();

    $payload['poolSize'] = raffle_eligible_count();

    $next = raffle_next_no();
    $when = $next === null ? null : raffle_reveal_at($next);

    if ($next !== null && $when !== null) {
        $payload['nextDraw'] = [
            'drawNo'   => $next,
            'revealAt' => $when->format(DateTimeInterface::ATOM),
            'label'    => $when->format('j M Y, g:i a'),
        ];
    }

    foreach (raffle_revealed_draws($history) as $draw) {
        $winners = array_map('raffle_public_winner', raffle_winners_for((int) $draw['id']));

        if (!$winners) {
            continue;
        }

        $payload['draws'][] = [
            'drawNo'    => (int) $draw['draw_no'],
            'revealAt'  => date(DateTimeInterface::ATOM, strtotime((string) $draw['reveal_at'])),
            'label'     => date('j M Y', strtotime((string) $draw['reveal_at'])),
            'goldGrams' => (float) $draw['gold_grams'],
            'winners'   => $winners,
        ];
    }

    return $payload;
}
