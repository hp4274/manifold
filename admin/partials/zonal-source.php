<?php
/**
 * One Zonal C&F's Details drawer markup.
 */

declare(strict_types=1);

$srcZonalId     = (int) $srcZonal['id'];
$srcDistributors= distributors_for_zonal($srcZonalId);
$srcStock       = stock_balance('zonal_cf', $srcZonalId);
$srcPrincipal   = principal_cf_for_zonal($srcZonal);
$srcWaiting     = ($srcZonal['approval_status'] ?? 'approved') === 'pending';
?>
<div class="drawer-source" id="detail-zonal_cf-<?= $srcZonalId ?>" hidden>

  <nav class="detail-tabs" role="tablist" aria-label="Sections">
    <button type="button" class="detail-tab is-active" data-tab="0" role="tab" aria-selected="true">Details</button>
    <button type="button" class="detail-tab" data-tab="1" role="tab" aria-selected="false">
      Distributors <span class="detail-tab__count"><?= count($srcDistributors) ?></span>
    </button>
    <button type="button" class="detail-tab" data-tab="2" role="tab" aria-selected="false">
      Stock <span class="detail-tab__count"><?= (int) $srcStock['units'] ?></span>
    </button>
  </nav>

  <div class="detail-panels">
    <section class="detail-panel is-active" data-panel="0" role="tabpanel">

      <?php if ($srcWaiting): ?>
        <div class="decide-bar">
          <div class="decide-bar__text">
            <p class="decide-bar__title">Waiting for your approval</p>
            <p class="decide-bar__note">
              Approving issues their MZ-series code and authorizes territory distributor onboarding; turning them down keeps them inactive.
            </p>
          </div>
          <div class="decide-bar__actions">
            <form method="post" action="zonal-cf">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $srcZonalId ?>">
              <button type="submit" name="action" value="approve_zonal" class="btn btn--primary">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Approve
              </button>
            </form>
            <form method="post" action="zonal-cf" data-confirm="Turn down <?= e($srcZonal['full_name']) ?>?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $srcZonalId ?>">
              <button type="submit" name="action" value="reject_zonal" class="btn btn--ghost">Turn down</button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <div class="detail-block">
        <p class="detail-block__title">Identity &amp; Hierarchy</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>Code</dt>
            <dd><strong><?= e($srcZonal['zonal_code'] ?: 'None (pending approval)') ?></strong></dd>
          </div>
          <div class="detail-field">
            <dt>Principal C&amp;F</dt>
            <dd>
              <?php if ($srcPrincipal): ?>
                <strong><?= e($srcPrincipal['full_name']) ?></strong> (<?= e($srcPrincipal['principal_code']) ?>)
              <?php else: ?>
                None / Unassigned
              <?php endif; ?>
            </dd>
          </div>
          <div class="detail-field">
            <dt>Company</dt>
            <dd><?= e($srcZonal['company'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Terms &amp; Conditions</dt>
            <dd>
              <?php if (!empty($srcZonal['terms_accepted'])): ?>
                <span class="pill pill--good"><i class="bi bi-check-circle"></i> Accepted</span>
                <span class="field-hint">Recorded <?= e(format_datetime($srcZonal['terms_accepted_at'] ?? '')) ?> (<?= e(partner_terms('zonal_cf')['summary']) ?>)</span>
              <?php else: ?>
                <span class="pill pill--warning">Pending</span>
              <?php endif; ?>
            </dd>
          </div>
          <div class="detail-field">
            <dt>Status</dt>
            <dd><?= $srcZonal['is_active'] ? 'Active' : 'Switched off' ?></dd>
          </div>
        </dl>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">Contact &amp; Location</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>Email</dt>
            <dd><?= e($srcZonal['email'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Mobile</dt>
            <dd><?= e($srcZonal['mobile_number'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Address</dt>
            <dd><?= e($srcZonal['address'] ?: '—') ?>, <?= e($srcZonal['city'] ?: '—') ?>, <?= e($srcZonal['state'] ?: '—') ?> - <?= e($srcZonal['pin_code'] ?: '—') ?></dd>
          </div>
        </dl>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">Banking &amp; Tax</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>PAN</dt>
            <dd><?= e($srcZonal['pan_number'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>GST</dt>
            <dd><?= e($srcZonal['gst_number'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Bank &amp; Account</dt>
            <dd><?= e($srcZonal['bank_name'] ?: '—') ?> &bull; <?= e($srcZonal['bank_account'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>IFSC &amp; UPI</dt>
            <dd><?= e($srcZonal['bank_ifsc'] ?: '—') ?> &bull; <?= e($srcZonal['upi_id'] ?: '—') ?></dd>
          </div>
        </dl>
      </div>
    </section>

    <section class="detail-panel" data-panel="1" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">Assigned Distributors</p>
        <?php if (!$srcDistributors): ?>
          <p class="field-hint">No distributors assigned under this Zonal C&amp;F yet.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Distributor</th>
                <th>Code</th>
                <th>Location</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($srcDistributors as $d): ?>
                <tr>
                  <td><strong><?= e($d['full_name']) ?></strong></td>
                  <td><?= e($d['distributor_code'] ?: 'Pending') ?></td>
                  <td><?= e($d['city'] ?: $d['state']) ?></td>
                  <td><?= $d['is_active'] ? 'Active' : 'Paused' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>

    <section class="detail-panel" data-panel="2" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">Stock Balance</p>
        <div class="tiles">
          <span class="tile <?= e(stock_tile_class((int) $srcStock['stove']['units'])) ?>">
            <span class="eyebrow">Stoves</span>
            <strong><?= (int) $srcStock['stove']['units'] ?></strong>
            <?= stock_badge((int) $srcStock['stove']['units']) ?>
          </span>
          <span class="tile <?= e(stock_tile_class((int) $srcStock['tuktuk']['units'])) ?>">
            <span class="eyebrow">TukTuk Kits</span>
            <strong><?= (int) $srcStock['tuktuk']['units'] ?></strong>
            <?= stock_badge((int) $srcStock['tuktuk']['units']) ?>
          </span>
          <span class="tile <?= e(stock_tile_class((int) $srcStock['units'])) ?>">
            <span class="eyebrow">Total Shelf Value</span>
            <strong><?= e(money((float) $srcStock['value'])) ?></strong>
            <?= stock_badge((int) $srcStock['units']) ?>
          </span>
        </div>
      </div>
    </section>
  </div>
</div>
