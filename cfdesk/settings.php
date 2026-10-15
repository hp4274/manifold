<?php
/**
 * Commission for each partner under you, one at a time.
 * Blank = the standard rate. A rate cannot exceed your own: you cannot pay down
 * the line more than you earn.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

$pageTitle = 'Settings';
$pageLead  = 'Set the commission of each partner under you, individually.';
$activeNav = 'settings';

$ceiling = cf_own_rate($tier, $me);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $kind = (string) ($_POST['kind'] ?? '');
    $row  = in_array($kind, ['zonal_cf', 'distributor'], true)
        ? cf_owned($tier, $me, $kind, (int) ($_POST['id'] ?? 0))
        : null;

    if (!$row) {
        $_SESSION['cfdesk_error'] = 'That partner is not one of yours.';
    } else {
        [$rate, $err] = cf_rate_parse((string) ($_POST['commission_rate'] ?? ''), $ceiling);

        if ($err !== '') {
            $_SESSION['cfdesk_error'] = $row['full_name'] . ': ' . $err;
        } else {
            db()->prepare('UPDATE ' . ($kind === 'zonal_cf' ? 'zonal_cf' : 'distributors') . ' SET commission_rate = ? WHERE id = ?')
                ->execute([$rate, (int) $row['id']]);

            $_SESSION['cfdesk_flash'] = $row['full_name'] . ($rate === null
                ? ' now earns the standard ' . rate_short(commission_rate($kind)) . '.'
                : ' now earns ' . rate_short((float) $rate) . '.');
        }
    }

    header('Location: settings?as=' . $tier);
    exit;
}

$flash = cf_flash();
$error = (string) ($_SESSION['cfdesk_error'] ?? '');
unset($_SESSION['cfdesk_error']);

$groups = [];

if ($tier === 'principal_cf') {
    $groups['zonal_cf'] = ['Zonal C&F', cf_zonals($tier, $me), 'zonal_code'];
}

$groups['distributor'] = ['Distributors', cf_distributors($tier, $me), 'distributor_code'];

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?><p class="alert alert--ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="alert alert--error"><?= e($error) ?></p><?php endif; ?>

<div class="tiles">
  <span class="tile"><span class="eyebrow">Your rate</span><strong><?= e(rate_short($ceiling)) ?></strong>
    <span class="tile__stats"><span class="tile__stat">the most you can give anyone below you</span></span></span>
  <?php foreach ($groups as $kind => [$title]): ?>
    <span class="tile"><span class="eyebrow">Standard for <?= e($title) ?></span><strong><?= e(rate_short(commission_rate($kind))) ?></strong>
      <span class="tile__stats"><span class="tile__stat">used when a partner has no rate of their own</span></span></span>
  <?php endforeach; ?>
</div>

<?php foreach ($groups as $kind => [$title, $rows, $codeCol]): ?>
  <div class="panel">
    <div class="panel__head">
      <div class="panel__head-text">
        <h2><?= e($title) ?></h2>
        <span class="eyebrow">percent of the sale, excluding GST · new sales only</span>
      </div>
    </div>

    <?php if (!$rows): ?>
      <p class="empty">None yet. Add one from the <?= $kind === 'zonal_cf' ? 'Zonal C&amp;F' : 'Distributors' ?> section.</p>
    <?php else: ?>
      <ul class="rate-list">
        <?php foreach ($rows as $r): [$stText, $stClass] = cf_status($r); ?>
          <li class="rate-row">
            <div class="rate-row__who">
              <strong><?= e($r['full_name']) ?></strong>
              <span class="cell-sub"><?= e((string) ($r[$codeCol] ?: 'no code yet')) ?>
                <?= $kind === 'distributor' && $tier === 'principal_cf' && $r['zonal_name'] ? ' · ' . e($r['zonal_name']) : '' ?></span>
            </div>
            <span class="pill <?= e($stClass) ?>"><?= e($stText) ?></span>
            <span class="rate-row__now">
              <strong><?= e(rate_short(commission_rate($kind, (int) $r['id']))) ?></strong>
              <span class="cell-sub"><?= $r['commission_rate'] === null ? 'standard' : 'custom' ?></span>
            </span>
            <form method="post" action="settings?as=<?= e($tier) ?>" class="rate-row__form">
              <?= csrf_field() ?>
              <input type="hidden" name="kind" value="<?= e($kind) ?>">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <label class="visually-hidden" for="rate-<?= e($kind) ?>-<?= (int) $r['id'] ?>">Commission for <?= e($r['full_name']) ?></label>
              <span class="rate-input">
                <input id="rate-<?= e($kind) ?>-<?= (int) $r['id'] ?>" name="commission_rate" type="number"
                       step="0.01" min="0" max="<?= e(number_format($ceiling, 2, '.', '')) ?>"
                       value="<?= $r['commission_rate'] === null ? '' : e(rtrim(rtrim((string) $r['commission_rate'], '0'), '.')) ?>"
                       placeholder="<?= e(rtrim(rtrim(number_format(commission_rate($kind), 2, '.', ''), '0'), '.')) ?>">
                <span aria-hidden="true">%</span>
              </span>
              <button type="submit" class="btn btn--primary btn--sm">Save</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
