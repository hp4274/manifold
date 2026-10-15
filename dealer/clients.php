<?php
/**
 * The dealer's own customers, and how far each sale has got.
 *
 * A dealer may correct a client's own details from here — name, contact,
 * address and the rest — but not their money. Receipts, payment proofs, UTR
 * references and the identity documents they uploaded stay in the admin;
 * dealer_client_view() is what decides what this page shows, and
 * client_fields() what the edit screen may write.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$dealer    = require_dealer();
$dealerId  = (int) $dealer['id'];
$pageTitle = 'Clients';
$pageLead  = 'Everyone who applied through your link.';
$activeNav = 'clients';

/* the tiles count every client, the table shows one page of them */
$allClients = dealer_own_clients($dealerId);
$paging     = paged(count($allClients), $_GET['page'] ?? 1);
$clients    = array_slice($allClients, $paging['offset'], LIST_PER_PAGE);

$counts = ['all' => count($allClients), 'earned' => 0, 'waiting' => 0, 'rejected' => 0];

foreach ($allClients as $client) {
    if ($client['status'] === 'rejected') {
        $counts['rejected']++;
    } elseif ($client['earned']) {
        $counts['earned']++;
    } else {
        $counts['waiting']++;
    }
}

$flash = (string) ($_SESSION['dealer_flash'] ?? '');
unset($_SESSION['dealer_flash']);

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if (isset($_GET['collected'])): ?>
  <p class="alert alert--ok"><i class="bi bi-check-circle-fill"></i> Payment collected and verified successfully! Receipt has been issued.</p>
<?php endif; ?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">Clients</span>
    <strong><?= (int) $counts['all'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">applied through your link</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Earning you commission</span>
    <strong><?= (int) $counts['earned'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">complete, both payments verified</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still in progress</span>
    <strong><?= (int) $counts['waiting'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">worth a call — commission follows completion</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Not proceeding</span>
    <strong><?= (int) $counts['rejected'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">closed by the office</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Clients</h2>
      <span class="eyebrow">
        <?= (int) $paging['from'] ?>–<?= (int) $paging['to'] ?> of <?= (int) $paging['total'] ?>
      </span>
    </div>
    <a class="btn btn--ghost btn--sm" href="import">
      <i class="bi bi-upload" aria-hidden="true"></i> Import from a spreadsheet
    </a>
  </div>

  <?php if (!$clients): ?>
    <p class="empty">
      Nobody has applied through your link yet. Use <strong>Add a client</strong> above to open the form
      with your code already in it.
    </p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealer-clients data-table--stack">
        <colgroup>
          <col style="width:22%">
          <col style="width:13%">
          <col style="width:8%">
          <col style="width:13%">
          <col style="width:18%">
          <col style="width:14%">
          <col style="width:12%">
        </colgroup>
        <thead>
          <tr>
            <th>Client</th>
            <th>Booking number</th>
            <th>Product</th>
            <th>KYC Documents</th>
            <th>Progress</th>
            <th>Your commission</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $client): ?>
            <?php $progress = partner_progress($client['status'], (string) ($client['payment_plan'] ?? '')); ?>
            <tr>
              <td data-lead>
                <div class="cell-stack">
                  <strong><?= e($client['full_name']) ?></strong>
                  <span class="cell-sub"><?= e($client['email']) ?></span>
                  <?php if ($client['mobile_number'] !== ''): ?>
                    <span class="cell-sub"><?= e($client['mobile_number']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td data-label="Booking">
                <div class="cell-stack">
                  <span class="drawer__code"><?= e($client['reference_code']) ?></span>
                  <span class="cell-sub"><?= e(format_datetime($client['created_at'])) ?></span>
                </div>
              </td>
              <td data-label="Product"><?= e(product_label($client['product'])) ?></td>
              <td data-label="KYC Documents">
                <div class="cell-stack">
                  <?php if ($client['is_kyc_verified']): ?>
                    <span class="pill pill--complete" style="font-size:11px;"><i class="bi bi-patch-check-fill"></i> Verified</span>
                  <?php else: ?>
                    <span class="pill pill--pending" style="font-size:11px;"><i class="bi bi-hourglass-split"></i> Doc Pending</span>
                  <?php endif; ?>
                  <?php if (!empty($client['cancellation_locked'])): ?>
                    <span class="cell-sub" style="font-size:11px;"><i class="bi bi-shield-lock"></i> Locked</span>
                  <?php endif; ?>
                </div>
              </td>
              <td data-label="Progress">
                <div class="cell-stack">
                  <span class="pill pill--<?= e($client['status']) ?>"><?= e($progress['label']) ?></span>
                  <?php if ($progress['step'] > 0): ?>
                    <?php /* the bar says how far along, never how much is outstanding */ ?>
                    <span class="progress-bar" role="img"
                          aria-label="Stage <?= (int) $progress['step'] ?> of <?= (int) $progress['of'] ?>">
                      <span class="progress-bar__fill"
                            style="width:<?= (int) round($progress['step'] / $progress['of'] * 100) ?>%"></span>
                    </span>
                    <span class="cell-sub">Stage <?= (int) $progress['step'] ?> of
                      <?= (int) $progress['of'] ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="td-amount" data-label="Commission">
                <strong><?= e(money($client['dealer_commission'])) ?></strong>
                <span class="cell-sub">
                  <?= $client['earned'] ? 'earned' : 'once the sale completes' ?>
                </span>
              </td>
              <td data-label="Actions">
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                  <?php if ($client['is_kyc_verified'] && !in_array($client['status'], ['complete', 'rejected', 'cancelled', 'refunded', 'expired'], true)): ?>
                    <a class="btn btn--primary btn--sm" href="collect-payment?id=<?= (int) $client['id'] ?>" title="Collect customer payment">
                      <i class="bi bi-cash-coin"></i> Collect
                    </a>
                  <?php elseif (!$client['is_kyc_verified'] && !in_array($client['status'], ['complete', 'rejected', 'cancelled', 'refunded', 'expired'], true)): ?>
                    <span class="btn btn--ghost btn--sm" style="opacity:0.5;cursor:not-allowed;" title="Documents must be verified first">
                      <i class="bi bi-lock-fill"></i> Unverified
                    </span>
                  <?php endif; ?>
                  <a class="btn btn--ghost btn--sm" href="client-edit?id=<?= (int) $client['id'] ?>">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php
      $pagerPage  = $paging['page'];
      $pagerPages = $paging['pages'];
      $pagerTotal = $paging['total'];
      $pagerFrom  = $paging['from'];
      $pagerTo    = $paging['to'];
      $pagerBase  = 'clients';
      require __DIR__ . '/../admin/partials/pager.php';
    ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
