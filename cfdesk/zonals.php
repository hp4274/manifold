<?php
/** Principal only: add, edit and switch on/off the Zonal C&Fs under them. */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

if ($tier !== 'principal_cf') {
    header('Location: ./?as=' . $tier);
    exit;
}

$pageTitle = 'Zonal C&F';
$pageLead  = 'The zonal partners under you.';
$activeNav = 'zonals';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $z = cf_owned($tier, $me, 'zonal_cf', (int) ($_POST['id'] ?? 0));

    if ($z && ($_POST['action'] ?? '') === 'toggle') {
        db()->prepare('UPDATE zonal_cf SET is_active = 1 - is_active WHERE id = ?')->execute([(int) $z['id']]);

        cf_done($z['is_active']
            ? $z['full_name'] . ' is switched off. Their code no longer books commission.'
            : $z['full_name'] . ' is active again.', 'zonals', $tier);
    }

    cf_done('That Zonal C&F is not one of yours.', 'zonals', $tier);
}

$flash = cf_flash();
$rows  = cf_zonals($tier, $me);

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?><p class="alert alert--ok"><?= e($flash) ?></p><?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your Zonal C&amp;Fs</h2>
      <span class="eyebrow"><?= count($rows) ?> in total · <?= e(child_cap_usage('principal_cf', (int) $me['id'])) ?> allowed, set by the office</span>
    </div>
    <a class="btn btn--primary btn--sm" href="partner-form?kind=zonal_cf&amp;as=<?= e($tier) ?>">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a Zonal C&amp;F
    </a>
  </div>

  <?php if (!$rows): ?>
    <p class="empty">No Zonal C&amp;F under you yet. Add one — the office approves them and issues the code.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--stack">
        <thead><tr><th>Name</th><th>Code</th><th>City</th><th>Distributors</th><th>Commission</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $z): [$stText, $stClass] = cf_status($z); ?>
            <tr>
              <td data-label="Name"><strong><?= e($z['full_name']) ?></strong>
                <span class="cell-sub"><?= e((string) ($z['email'] ?? '')) ?></span></td>
              <td data-label="Code"><?= e((string) ($z['zonal_code'] ?: '—')) ?></td>
              <td data-label="City"><?= e((string) ($z['city'] ?? '')) ?></td>
              <td data-label="Distributors"><?= e(child_cap_usage('zonal_cf', (int) $z['id'])) ?></td>
              <td data-label="Commission"><?= e(rate_short(commission_rate('zonal_cf', (int) $z['id']))) ?>
                <?php if ($z['commission_rate'] === null): ?><span class="cell-sub">standard</span><?php endif; ?></td>
              <td data-label="Status"><span class="pill <?= e($stClass) ?>"><?= e($stText) ?></span></td>
              <td class="td-actions">
                <a class="btn btn--ghost btn--sm" href="partner-form?kind=zonal_cf&amp;id=<?= (int) $z['id'] ?>&amp;as=<?= e($tier) ?>">Edit</a>
                <?php if ($z['approval_status'] === 'approved'): ?>
                  <form method="post" action="zonals?as=<?= e($tier) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $z['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm"><?= $z['is_active'] ? 'Switch off' : 'Switch on' ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
