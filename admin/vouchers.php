<?php
/**
 * Commission claims, as the office sees them.
 *
 * R&F checks a bundle and puts it here. The office is the only party that can
 * say the money is owed — approving releases the funds to R&F, who make the
 * transfers and record them. Rejecting sends the whole thing back and the
 * dealers' vouchers return to their distributor rather than dying.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Commission';
$pageLead   = 'What partners have claimed, and what has been funded.';
$activeType = 'vouchers';

$error = '';

$flash = (string) ($_SESSION['vouchers_flash'] ?? '');
unset($_SESSION['vouchers_flash']);

/** Finish an action: remember what happened, then reload as a plain GET. */
function vouchers_done(string $message): void
{
    $_SESSION['vouchers_flash'] = $message;

    header('Location: vouchers');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action   = (string) ($_POST['action'] ?? '');
    $bundleId = (int) ($_POST['bundle_id'] ?? 0);
    $bundle   = voucher($bundleId);
    $actor    = 'the office (' . $user['name'] . ')';

    if (!$bundle) {
        $error = 'That bundle no longer exists.';
    } elseif ($action === 'fund') {
        /* one transfer against the whole bundle: R&F splits it from there */
        $error = voucher_move_bundle($bundleId, 'funded', $actor, ['with_admin'], 'Approved and funded');

        if ($error === '') {
            vouchers_done('Funded. R&F can pay the partners in that bundle now.');
        }
    } elseif ($action === 'reject') {
        $error = voucher_reject($bundleId, $actor, (string) ($_POST['reason'] ?? ''));

        if ($error === '') {
            vouchers_done('Turned down. Nothing is paid, and the sales in it can be claimed again.');
        }
    } else {
        $error = 'Unknown action.';
    }
}

$waiting = voucher_bundles(['with_admin']);
$funded  = voucher_bundles(['funded']);
$inFlight = voucher_bundles(['with_rf']);

$sum = static function (array $bundles): float {
    $total = 0.0;

    foreach ($bundles as $bundle) {
        $total += voucher_bundle_total((int) $bundle['id']);
    }

    return $total;
};

$paidTotal = (float) db()->query(
    "SELECT COALESCE(SUM(amount), 0) FROM commission_vouchers WHERE status = 'paid'"
)->fetchColumn();

$owedTotal = (float) db()->query(
    "SELECT COALESCE(SUM(dealer_commission), 0) + COALESCE(SUM(distributor_commission), 0)
       FROM applications WHERE " . COMMISSION_EARNED_SQL
)->fetchColumn();

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">Waiting on you</span>
    <strong class="stock-figure"><?= count($waiting) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($sum($waiting))) ?> claimed</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Funded, not yet paid</span>
    <strong class="stock-figure"><?= count($funded) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat"><?= e(money($sum($funded))) ?> with R&amp;F</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Commission earned</span>
    <strong class="stock-figure"><?= e(money($owedTotal)) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">on every completed sale, all partners</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid through vouchers</span>
    <strong class="stock-figure"><?= e(money($paidTotal)) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across every voucher settled</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Presented by R&amp;F</h2>
      <span class="eyebrow">Approve to release the money, or send it back</span>
    </div>
  </div>

  <?php if (!$waiting): ?>
    <p class="empty">Nothing waiting. Claims appear here once R&amp;F has checked them.</p>
  <?php else: ?>
    <div class="panel__body">
      <?php foreach ($waiting as $bundle): ?>
        <?php
          $bundleId = (int) $bundle['id'];
          $children = voucher_bundle_children($bundleId);
          $ownLines = voucher_lines($bundleId);
        ?>
        <section class="voucher">
          <header class="voucher__head">
            <div class="voucher__who">
              <p class="eyebrow">Bundle #<?= $bundleId ?> · <?= e($bundle['party_code']) ?></p>
              <h3><?= e($bundle['party_name']) ?></h3>
              <p class="voucher__meta">
                Raised <?= e(format_datetime($bundle['raised_at'])) ?> ·
                <?= count($children) ?> dealer<?= count($children) === 1 ? '' : 's' ?> ·
                cycle <?= e(format_date($bundle['cycle_date'])) ?>
              </p>
            </div>
            <div class="voucher__sum">
              <strong class="stock-figure"><?= e(money(voucher_bundle_total($bundleId))) ?></strong>
            </div>
          </header>

          <?php /* the sales behind every line, because approving this is saying
                   those sales really did complete and really do owe this */ ?>
          <div class="table-wrap">
            <table class="data-table" data-paged="10">
              <colgroup>
                <col style="width:26%">
                <col style="width:18%">
                <col style="width:26%">
                <col style="width:14%">
                <col style="width:16%">
              </colgroup>
              <thead>
                <tr>
                  <th>Who is owed</th>
                  <th>Booking number</th>
                  <th>Client</th>
                  <th>Completed</th>
                  <th>Their share</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ownLines as $line): ?>
                  <tr>
                    <td>
                      <div class="cell-stack">
                        <strong><?= e($bundle['party_name']) ?></strong>
                        <span class="cell-sub">Distributor</span>
                      </div>
                    </td>
                    <td><span class="drawer__code"><?= e($line['reference_code']) ?></span></td>
                    <td><?= e($line['full_name']) ?></td>
                    <td><span class="cell-sub"><?= e(format_date($line['completed_at'])) ?></span></td>
                    <td class="td-amount"><?= e(money((float) $line['amount'])) ?></td>
                  </tr>
                <?php endforeach; ?>

                <?php foreach ($children as $child): ?>
                  <?php foreach (voucher_lines((int) $child['id']) as $line): ?>
                    <tr>
                      <td>
                        <div class="cell-stack">
                          <strong><?= e($child['party_name']) ?></strong>
                          <span class="cell-sub">Dealer · <?= e($child['party_code']) ?></span>
                        </div>
                      </td>
                      <td><span class="drawer__code"><?= e($line['reference_code']) ?></span></td>
                      <td><?= e($line['full_name']) ?></td>
                      <td><span class="cell-sub"><?= e(format_date($line['completed_at'])) ?></span></td>
                      <td class="td-amount"><?= e(money((float) $line['amount'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="voucher__actions">
            <form method="post"
                  data-confirm="Fund this bundle? R&amp;F pays the partners from it.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="fund">
              <input type="hidden" name="bundle_id" value="<?= $bundleId ?>">
              <button type="submit" class="btn btn--primary">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Approve and fund
              </button>
            </form>

            <form method="post" class="decide__reason"
                  data-confirm="Turn this bundle down? Nothing is paid and the dealers' vouchers go back to their distributor.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reject">
              <input type="hidden" name="bundle_id" value="<?= $bundleId ?>">
              <label class="visually-hidden" for="reject-<?= $bundleId ?>">Why it is being turned down</label>
              <input id="reject-<?= $bundleId ?>" name="reason" type="text" maxlength="255"
                     placeholder="Why? They see this">
              <button type="submit" class="btn btn--ghost">Turn down</button>
            </form>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Elsewhere in the chain</h2>
      <span class="eyebrow">Nothing for you to do on these</span>
    </div>
  </div>

  <div class="table-wrap">
    <table class="data-table">
      <colgroup>
        <col style="width:10%">
        <col style="width:30%">
        <col style="width:20%">
        <col style="width:20%">
        <col style="width:20%">
      </colgroup>
      <thead>
        <tr>
          <th>#</th>
          <th>Distributor</th>
          <th>Worth</th>
          <th>Where it is</th>
          <th>Raised</th>
        </tr>
      </thead>
      <tbody>
        <?php $elsewhere = array_merge($inFlight, $funded); ?>

        <?php if (!$elsewhere): ?>
          <tr class="row-empty">
            <td colspan="5">No entry found — no claim is in flight.</td>
          </tr>
        <?php endif; ?>

        <?php foreach ($elsewhere as $row): ?>
          <tr>
            <td><?= (int) $row['id'] ?></td>
            <td>
              <div class="cell-stack">
                <strong><?= e($row['party_name']) ?></strong>
                <span class="cell-sub"><?= e($row['party_code']) ?></span>
              </div>
            </td>
            <td class="td-amount stock-figure">
              <strong><?= e(money(voucher_bundle_total((int) $row['id']))) ?></strong>
            </td>
            <td>
              <span class="pill pill--<?= e(voucher_status_pill((string) $row['status'])) ?>">
                <?= e(voucher_status_label((string) $row['status'])) ?>
              </span>
            </td>
            <td><span class="cell-sub"><?= e(format_datetime($row['raised_at'])) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
