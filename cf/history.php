<?php
/**
 * Every voucher C&F has finished with, and what happened to it.
 *
 * A payment that is disputed later is settled from here: each row opens onto
 * its own trail of moves, with who made each one and when.
 */

declare(strict_types=1);

require_once __DIR__ . '/../admin/lib.php';

$user      = require_rf();
$pageTitle = 'History';
$pageLead  = 'Every voucher that has been paid, turned down or cancelled.';
$activeNav = 'history';

$settled = db()->query(
    "SELECT v.*,
            COALESCE(x.full_name, d.full_name) AS party_name,
            COALESCE(x.distributor_code, d.dealer_code) AS party_code
       FROM commission_vouchers v
       LEFT JOIN distributors x ON x.id = v.party_id AND v.party_type = 'distributor'
       LEFT JOIN dealers d      ON d.id = v.party_id AND v.party_type = 'dealer'
      WHERE v.status IN ('paid', 'rejected', 'cancelled')
      ORDER BY COALESCE(v.paid_at, v.decided_at, v.raised_at) DESC, v.id DESC
      LIMIT 200"
)->fetchAll();

require __DIR__ . '/partials/layout-top.php';
?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Settled vouchers</h2>
      <span class="eyebrow">The most recent 200</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table" data-paged="15">
      <colgroup>
        <col style="width:8%">
        <col style="width:24%">
        <col style="width:14%">
        <col style="width:16%">
        <col style="width:18%">
        <col style="width:20%">
      </colgroup>
      <thead>
        <tr>
          <th>#</th>
          <th>Who</th>
          <th>Amount</th>
          <th>State</th>
          <th>Settled</th>
          <th>Reference / reason</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$settled): ?>
          <tr class="row-empty">
            <td colspan="6">No entry found — nothing has been settled yet.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($settled as $row): ?>
          <tr>
            <td><?= (int) $row['id'] ?></td>
            <td>
              <div class="cell-stack">
                <strong><?= e($row['party_name'] ?? 'a deleted partner') ?></strong>
                <span class="cell-sub">
                  <?= $row['party_type'] === 'distributor' ? 'Distributor' : 'Dealer' ?>
                  <?= $row['party_code'] ? '· ' . e($row['party_code']) : '' ?>
                  <?= (int) $row['is_bundle'] === 1 ? ' · bundle' : '' ?>
                </span>
              </div>
            </td>
            <td class="td-amount stock-figure"><strong><?= e(money((float) $row['amount'])) ?></strong></td>
            <td>
              <span class="pill pill--<?= e(voucher_status_pill((string) $row['status'])) ?>">
                <?= e(voucher_status_label((string) $row['status'])) ?>
              </span>
            </td>
            <td><?= e(format_datetime($row['paid_at'] ?: ($row['decided_at'] ?: $row['raised_at']))) ?></td>
            <td>
              <span class="cell-sub">
                <?= e($row['payment_reference'] ?: ($row['reject_reason'] ?: '—')) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
