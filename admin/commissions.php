<?php
/**
 * Commission payouts: the office's end of the pass-down.
 *
 * Lines are released automatically 48 hours after the customer's payment was
 * verified (cron/commission-release.php, and again on every visit here). The
 * office then sends each top-of-chain partner — the Principal C&F, or the next
 * tier that is on the sale when there is none — everything released in their
 * network, with one UTR and one receipt. They pass it down the same way; see
 * commission_transfer() in lib.php. The office never pays a lower tier whose
 * sale has somebody above them.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Commission payouts';
$pageLead   = 'Released commission to send down the chain, and every line from the 48-hour hold to its partner.';
$activeType = 'commissions';

$flash = (string) ($_SESSION['commissions_flash'] ?? '');
unset($_SESSION['commissions_flash']);
$error = '';

commission_release_due();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ((string) ($_POST['action'] ?? '') === 'passdown') {
        [$done, $error] = commission_transfer_post('office', null, (string) $user['name']);

        if ($error === '') {
            $_SESSION['commissions_flash'] = $done;
            header('Location: commissions');
            exit;
        }
    }
}

$status = (string) ($_GET['status'] ?? '');
$party  = (string) ($_GET['party'] ?? '');
$where  = [];
$params = [];

if (in_array($status, COMMISSION_PAYOUT_STATUSES, true)) {
    $where[]  = 'c.payout_status = ?';
    $params[] = $status;
}

if (in_array($party, ['principal_cf', 'zonal_cf', 'distributor', 'dealer'], true)) {
    $where[]  = 'c.party_type = ?';
    $params[] = $party;
}

$stmt = db()->prepare(
    "SELECT c.*, a.reference_code, a.full_name AS client,
            COALESCE(p.full_name, z.full_name, d.full_name, dl.full_name) AS party_name
       FROM commission_lines c
       JOIN applications a ON a.id = c.application_id
       LEFT JOIN principal_cf  p  ON c.party_type = 'principal_cf'  AND p.id  = c.party_id
       LEFT JOIN zonal_cf      z  ON c.party_type = 'zonal_cf'      AND z.id  = c.party_id
       LEFT JOIN distributors  d  ON c.party_type = 'distributor'   AND d.id  = c.party_id
       LEFT JOIN dealers       dl ON c.party_type = 'dealer'        AND dl.id = c.party_id
      " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
      ORDER BY c.id DESC LIMIT 2000'
);
$stmt->execute($params);
$lines = $stmt->fetchAll();

$counts = [];

foreach (db()->query('SELECT payout_status, COUNT(*) n, SUM(amount) total FROM commission_lines GROUP BY payout_status') as $r) {
    $counts[$r['payout_status']] = $r;
}

$tierLabels = ['principal_cf' => 'Principal C&F', 'zonal_cf' => 'Zonal C&F', 'distributor' => 'Distributor', 'dealer' => 'Dealer'];

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?><p class="alert alert--ok"><?= e($flash) ?></p><?php endif; ?>
<?php if ($error !== ''): ?><p class="alert alert--error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="tiles">
  <?php foreach (COMMISSION_PAYOUT_STATUSES as $st): ?>
    <a class="tile" href="commissions?status=<?= e($st) ?>">
      <span class="eyebrow"><?= e(payout_status_label($st)) ?></span>
      <strong><?= e(money((float) ($counts[$st]['total'] ?? 0))) ?></strong>
      <span class="tile__stats"><span class="tile__stat"><?= (int) ($counts[$st]['n'] ?? 0) ?> lines</span></span>
    </a>
  <?php endforeach; ?>
</div>

<?php
$pdType    = 'office';
$pdId      = null;
$pdAction  = 'commissions';
$pdReceipt = static fn (array $t): string => 'file.php?path=' . rawurlencode((string) $t['proof_path']) . '&dir=payments';
require __DIR__ . '/partials/commission-passdown.php';
?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Commission lines</h2>
      <span class="eyebrow">
        <a href="commissions">all</a> ·
        <?php foreach ($tierLabels as $k => $label): ?>
          <a href="commissions?party=<?= e($k) ?>"><?= e($label) ?></a><?= $k === 'dealer' ? '' : ' · ' ?>
        <?php endforeach; ?>
      </span>
    </div>
  </div>

  <?php if (!$lines): ?>
    <p class="empty">No commission lines match.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--stack" data-paged="10" data-export="commission-lines">
        <thead>
          <tr>
            <th>Partner</th><th>Sale</th><th>Stage</th><th>Base</th><th>Rate</th>
            <th>Commission</th><th>Release</th><th>Status</th><th>With</th><th data-skip>Proof</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lines as $l): ?>
            <tr>
              <td data-lead><strong><?= e((string) $l['party_name']) ?></strong>
                  <span class="cell-sub"><?= e($tierLabels[$l['party_type']] ?? $l['party_type']) ?></span></td>
              <td data-label="Sale"><?= e($l['reference_code']) ?><span class="cell-sub"><?= e($l['client']) ?></span></td>
              <td data-label="Stage"><?= e(tranche_label((string) $l['stage'])) ?></td>
              <td data-label="Base"><?= e(money((float) $l['base_amount'])) ?></td>
              <td data-label="Rate"><?= e(rate_short((float) $l['rate'])) ?></td>
              <td data-label="Commission"><strong><?= e(money((float) $l['amount'])) ?></strong></td>
              <td data-label="Release"><?= e(format_datetime($l['release_at'])) ?></td>
              <td data-label="Status"><span class="pill"><?= e(payout_status_label((string) $l['payout_status'])) ?></span></td>
              <td data-label="With">
                <?php /* who is holding the money right now */ ?>
                <?= e($l['holder_type'] === null
                    ? COMMISSION_TIER_LABELS['office']
                    : ($l['holder_type'] === $l['party_type'] && (int) $l['holder_id'] === (int) $l['party_id']
                        ? (string) $l['party_name']   /* arrived: no lookup per row */
                        : commission_party_name((string) $l['holder_type'], (int) $l['holder_id']))) ?>
              </td>
              <td data-label="Proof">
                <?php if (!empty($l['proof_path'])): ?>
                  <a href="file.php?path=<?= e(rawurlencode((string) $l['proof_path'])) ?>&amp;dir=payments"
                     target="_blank" rel="noopener">Receipt</a>
                  <span class="cell-sub">UTR <?= e((string) $l['payout_utr']) ?></span>
                <?php else: ?>-<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
