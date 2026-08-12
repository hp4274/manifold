<?php
/**
 * Raffle: how long until the next reveal, and who won.
 *
 * Nothing is drawn here. The office holds the draw in front of witnesses, as the
 * promotion says, and then writes down who won — one search box takes a name, a
 * reference code or a mobile number and puts that person on the list.
 *
 * A list is private until its reveal time and appears on the website after it.
 * It stays editable either way: a name can be taken off and another put on at
 * any point, because the office, not this page, decides what is right.
 *
 * Setup here covers the calendar only. The prize itself — grams of gold, the
 * market rate and the cash discount the website quotes — is not editable from
 * this screen; those four `raffle_*` rows in `settings` are changed directly in
 * the database. raffle-lib.php still reads them for the public feed.
 */

declare(strict_types=1);

require_once __DIR__ . '/raffle-lib.php';

$user       = require_login();
$pageTitle  = 'Raffle';
$pageLead   = 'The countdown to the next reveal, and the winners you record against it.';
$activeType = 'raffle';

$error = '';

/* carried across the redirect that follows every successful action */
$flash = (string) ($_SESSION['raffle_flash'] ?? '');
unset($_SESSION['raffle_flash']);

/* what was typed in the search box, kept across the redirect too */
$search = trim((string) ($_GET['q'] ?? ''));

/** Finish an action: remember what happened, then reload as a plain GET. */
function raffle_done(string $message, string $query = ''): void
{
    $_SESSION['raffle_flash'] = $message;

    header('Location: raffle.php' . ($query === '' ? '' : '?q=' . urlencode($query)));
    exit;
}

/** True when the raffle tables exist. They arrive with schema.sql. */
function raffle_tables_ready(): bool
{
    try {
        db()->query('SELECT 1 FROM raffle_draws LIMIT 1');

        return true;
    } catch (PDOException $e) {
        return false;
    }
}

$ready = raffle_tables_ready();

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');
    $keep   = trim((string) ($_POST['q'] ?? ''));

    if ($action === 'setup') {
        $firstRaw = trim((string) ($_POST['first_draw'] ?? ''));
        $first    = $firstRaw === '' ? '' : date('Y-m-d H:i:00', strtotime(str_replace('T', ' ', $firstRaw)));
        $cycle    = (int) ($_POST['cycle_days'] ?? 90);
        $winners  = (int) ($_POST['winner_count'] ?? 5);

        if ($firstRaw !== '' && strtotime(str_replace('T', ' ', $firstRaw)) === false) {
            $error = 'That first draw date could not be read.';
        } elseif ($cycle < 1 || $cycle > 730) {
            $error = 'A cycle has to be between 1 and 730 days.';
        } elseif ($winners < 1 || $winners > 50) {
            $error = 'A draw has between 1 and 50 winners.';
        } else {
            save_setting('raffle_enabled', isset($_POST['enabled']) ? '1' : '0');
            save_setting('raffle_first_draw', $first);
            save_setting('raffle_cycle_days', (string) $cycle);
            save_setting('raffle_winner_count', (string) $winners);

            raffle_config(true);
            raffle_sync();

            raffle_done($first === ''
                ? 'Saved. There is nothing to count down to until a first draw date is set.'
                : 'Saved. The next reveal is ' . date('j M Y, g:i a', strtotime($first)) . '.');
        }
    } elseif ($action === 'add') {
        $problem = raffle_add_winner(
            (int) ($_POST['draw_id'] ?? 0),
            (int) ($_POST['application_id'] ?? 0),
            (int) $user['id']
        );

        if ($problem !== '') {
            $error = $problem;
        } else {
            raffle_done('Added to the list.', $keep);
        }
    } elseif ($action === 'remove') {
        $problem = raffle_remove_winner((int) ($_POST['winner_id'] ?? 0), (int) $user['id']);

        if ($problem !== '') {
            $error = $problem;
        } else {
            raffle_done('Taken off the list.', $keep);
        }
    } else {
        $error = 'Unknown action.';
    }
}

$config = raffle_config(true);

if ($ready) {
    raffle_sync();
}

$nextNo   = $ready ? raffle_next_no() : null;
$nextDraw = $nextNo === null ? null : raffle_draw_by_no($nextNo);
$draws    = $ready ? raffle_all_draws(20) : [];
$eligible = $ready ? raffle_eligible_count() : 0;

/* the upcoming draw has its own panel, so keep it out of the list below */
$others = array_values(array_filter(
    $draws,
    static function ($draw) use ($nextDraw) {
        return $nextDraw === null || (int) $draw['id'] !== (int) $nextDraw['id'];
    }
));

$results = $ready && $search !== '' && $nextDraw
    ? raffle_search($search, (int) $nextDraw['id'])
    : [];

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<?php if (!$ready): ?>
  <p class="alert alert--error">
    <code>raffle_draws</code> and <code>raffle_winners</code> are missing from the
    <code><?= e(DB_NAME) ?></code> database, so there is nothing for the raffle to be kept in.
    Their structure is in <code>admin/schema.sql</code> — copy the two
    <code>CREATE TABLE</code> statements and the <code>raffle_*</code> settings out of it and run
    those. <strong>Do not import the whole file on a live site:</strong> it drops the database
    and everything in it.
  </p>
<?php else: ?>

<?php if (!raffle_running()): ?>
  <p class="alert alert--warn">
    The raffle is not running. Set a first draw date under <a href="#raffle-setup">Raffle setup</a> below
    and every following reveal is worked out from it, <?= (int) $config['cycle_days'] ?> days apart.
  </p>
<?php endif; ?>

<?php if ($nextDraw): ?>
  <?php
    $winners  = raffle_winners_for((int) $nextDraw['id']);
    $places   = (int) $nextDraw['winner_count'];
    $revealed = raffle_is_revealed($nextDraw);
  ?>
  <div class="panel panel--clock">
    <div class="panel__head">
      <h2>Draw <?= (int) $nextDraw['draw_no'] ?></h2>
      <span class="eyebrow"><?= $places ?> winners ·
        <?= e(rtrim(rtrim(number_format((float) $nextDraw['gold_grams'], 3), '0'), '.')) ?> g of gold each</span>
    </div>

    <div class="panel__body">
      <div class="clock clock--wide">
        <span class="eyebrow">Goes public in</span>
        <p class="clock__time" data-countdown="<?= e(date(DateTimeInterface::ATOM, strtotime((string) $nextDraw['reveal_at']))) ?>">—</p>
        <span class="clock__at"><?= e(format_datetime($nextDraw['reveal_at'])) ?></span>
      </div>

      <p class="clock__note">
        <?= count($winners) ?> of <?= $places ?> <?= $places === 1 ? 'place' : 'places' ?> filled.
        Hold the draw however you like and record the winners below — until
        <?= e(format_datetime($nextDraw['reveal_at'])) ?> the list is yours alone, and after it the website
        shows it. <?= (int) $eligible ?> applicants have paid in full and can be entered.
      </p>
    </div>
  </div>

  <div class="panel" id="add">
    <div class="panel__head">
      <h2>Add a winner</h2>
      <span class="eyebrow">By name, reference code or mobile</span>
    </div>

    <div class="panel__body">
      <?php /* posts as a plain GET without JS; admin.js answers as you type instead */ ?>
      <form method="get" class="finder" role="search" data-finder
            data-endpoint="raffle-search.php" data-draw="<?= (int) $nextDraw['id'] ?>">
        <label class="finder__field">
          <span class="visually-hidden">Search applicants by name, reference code or mobile</span>
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" name="q" value="<?= e($search) ?>" autocomplete="off"
                 aria-controls="raffleResults"
                 placeholder="Harsh Patel &nbsp;·&nbsp; MF-2026-00031 &nbsp;·&nbsp; 9773444404">
          <span class="finder__spinner" aria-hidden="true"></span>
        </label>
        <button type="submit" class="btn btn--primary finder__submit">Search</button>
        <a class="btn btn--ghost finder__clear" href="raffle.php"
           <?= $search === '' ? 'hidden' : '' ?>>Clear</a>
      </form>

      <p class="finder__hint">
        Only applicants who have paid in full are listed — that is who the promotion is open to.
        Part of a name is enough, and a mobile number can be typed with spaces, dashes or a country code.
      </p>

      <div id="raffleResults" aria-live="polite">
        <?php require __DIR__ . '/partials/raffle-results.php'; ?>
      </div>
    </div>
  </div>

  <div class="panel" id="winners">
    <div class="panel__head">
      <h2><?= $revealed ? 'Winners' : 'Winners — not public yet' ?></h2>
      <span class="eyebrow"><?= count($winners) ?> of <?= $places ?> recorded</span>
    </div>

    <?php if (!$winners): ?>
      <p class="empty">Nobody recorded yet. Search above to add the first winner.</p>
    <?php else: ?>
      <?php $draw = $nextDraw; require __DIR__ . '/partials/raffle-winners.php'; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach ($others as $draw): ?>
  <?php $winners = raffle_winners_for((int) $draw['id']); ?>
  <?php if (!$winners) { continue; } ?>
  <div class="panel">
    <div class="panel__head">
      <h2>Draw <?= (int) $draw['draw_no'] ?></h2>
      <span class="eyebrow">
        <?= raffle_is_revealed($draw) ? 'public since ' : 'goes public ' ?><?= e(format_datetime($draw['reveal_at'])) ?>
        · <?= count($winners) ?> <?= count($winners) === 1 ? 'winner' : 'winners' ?>
      </span>
    </div>

    <?php require __DIR__ . '/partials/raffle-winners.php'; ?>
  </div>
<?php endforeach; ?>

<div class="panel panel--open" id="raffle-setup">
  <div class="panel__head">
    <h2>Raffle setup</h2>
    <span class="eyebrow">The calendar the reveal dates come from</span>
  </div>

  <form method="post" class="panel__body">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="setup">

    <label class="toggle">
      <input type="checkbox" name="enabled" value="1" <?= $config['enabled'] ? 'checked' : '' ?>>
      <span class="toggle__track" aria-hidden="true"><span class="toggle__knob"></span></span>
      <span class="toggle__text">
        Run the raffle
        <span>Switched off, the button disappears from the website and the popup stops offering it.</span>
      </span>
    </label>

    <div class="field-row">
      <div class="field">
        <label for="first_draw">First draw revealed at</label>
        <input id="first_draw" name="first_draw" type="datetime-local"
               value="<?= $config['first_draw'] === '' ? '' : e(date('Y-m-d\TH:i', strtotime($config['first_draw']))) ?>">
        <span class="field-hint">
          Every following reveal is this date plus a whole number of cycles, so there is one date to keep
          right. Leave it empty and the raffle is not running.
        </span>
      </div>

      <div class="field">
        <label for="cycle_days">Days between draws</label>
        <input id="cycle_days" name="cycle_days" type="number" min="1" max="730"
               value="<?= (int) $config['cycle_days'] ?>" required>
        <span class="field-hint">90 for the promotion as advertised.</span>
      </div>

      <div class="field">
        <label for="winner_count">Winners per draw</label>
        <input id="winner_count" name="winner_count" type="number" min="1" max="50"
               value="<?= (int) $config['winner_count'] ?>" required>
        <span class="field-hint">How many places there are to fill in by hand.</span>
      </div>
    </div>

    <button type="submit" class="btn btn--primary">Save</button>
  </form>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
