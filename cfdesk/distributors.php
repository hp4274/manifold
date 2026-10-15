<?php
/** Add, edit and switch on/off the distributors under this partner. */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

$isPrincipal = $tier === 'principal_cf';
$pageTitle   = 'Distributors';
$pageLead    = $isPrincipal ? 'Every distributor under your zonal partners.' : 'The distributors under you.';
$activeNav   = 'distributors';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $d = cf_owned($tier, $me, 'distributor', (int) ($_POST['id'] ?? 0));

    if ($d && ($_POST['action'] ?? '') === 'toggle') {
        db()->prepare('UPDATE distributors SET is_active = 1 - is_active WHERE id = ?')->execute([(int) $d['id']]);

        cf_done($d['is_active']
            ? $d['full_name'] . ' is switched off. Their code no longer books commission.'
            : $d['full_name'] . ' is active again.', 'distributors', $tier);
    }

    cf_done('That distributor is not one of yours.', 'distributors', $tier);
}

$flash = cf_flash();
$rows  = cf_distributors($tier, $me);
$canAdd = !$isPrincipal || cf_zonals($tier, $me);

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?><p class="alert alert--ok"><?= e($flash) ?></p><?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Your distributors</h2>
      <span class="eyebrow"><?= count($rows) ?> in total<?php if (!$isPrincipal): ?> · <?= e(child_cap_usage('zonal_cf', (int) $me['id'])) ?> allowed<?php endif; ?></span>
    </div>
    <?php if ($canAdd): ?>
      <a class="btn btn--primary btn--sm" href="partner-form?kind=distributor&amp;as=<?= e($tier) ?>">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a distributor
      </a>
    <?php endif; ?>
  </div>

  <?php if (!$rows): ?>
    <p class="empty">
      <?= $canAdd ? 'No distributors yet. Add one — the office approves them and issues the code.'
                  : 'Add a Zonal C&amp;F first; a distributor sits under one.' ?>
    </p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--stack">
        <thead><tr>
          <th>Name</th><th>Code</th><?php if ($isPrincipal): ?><th>Zonal C&amp;F</th><?php endif; ?>
          <th>City</th><th>Dealers</th><th>Commission</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $d): [$stText, $stClass] = cf_status($d); ?>
            <tr>
              <td data-label="Name"><strong><?= e($d['full_name']) ?></strong>
                <span class="cell-sub"><?= e((string) ($d['email'] ?? '')) ?></span></td>
              <td data-label="Code"><?= e((string) $d['distributor_code']) ?></td>
              <?php if ($isPrincipal): ?><td data-label="Zonal C&amp;F"><?= e((string) $d['zonal_name']) ?></td><?php endif; ?>
              <td data-label="City"><?= e((string) ($d['city'] ?? '')) ?></td>
              <td data-label="Dealers"><?= e(child_cap_usage('distributor', (int) $d['id'])) ?></td>
              <td data-label="Commission"><?= e(rate_short(commission_rate('distributor', (int) $d['id']))) ?>
                <?php if ($d['commission_rate'] === null): ?><span class="cell-sub">standard</span><?php endif; ?></td>
              <td data-label="Status"><span class="pill <?= e($stClass) ?>"><?= e($stText) ?></span></td>
              <td class="td-actions">
                <a class="btn btn--ghost btn--sm" href="partner-form?kind=distributor&amp;id=<?= (int) $d['id'] ?>&amp;as=<?= e($tier) ?>">Edit</a>
                <?php if ($d['approval_status'] === 'approved'): ?>
                  <form method="post" action="distributors?as=<?= e($tier) ?>" class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button type="submit" class="btn btn--ghost btn--sm"><?= $d['is_active'] ? 'Switch off' : 'Switch on' ?></button>
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
