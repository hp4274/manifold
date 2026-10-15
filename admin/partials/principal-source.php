<?php
/**
 * One Principal C&F's Details drawer markup.
 */

declare(strict_types=1);

$srcPrincipalId = (int) $srcPrincipal['id'];
$srcZonals      = zonal_cf_for_principal($srcPrincipalId);
$srcStock       = stock_balance('principal_cf', $srcPrincipalId);
$srcWaiting     = ($srcPrincipal['approval_status'] ?? 'approved') === 'pending';
?>
<div class="drawer-source" id="detail-principal_cf-<?= $srcPrincipalId ?>" hidden>

  <nav class="detail-tabs" role="tablist" aria-label="Sections">
    <button type="button" class="detail-tab is-active" data-tab="0" role="tab" aria-selected="true">Details</button>
    <button type="button" class="detail-tab" data-tab="1" role="tab" aria-selected="false">
      Zonal C&amp;Fs <span class="detail-tab__count"><?= count($srcZonals) ?></span>
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
              Approving issues their MP-series code and authorizes regional operations; turning them down keeps them inactive.
            </p>
          </div>
          <div class="decide-bar__actions">
            <form method="post" action="principal-cf">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $srcPrincipalId ?>">
              <button type="submit" name="action" value="approve_principal" class="btn btn--primary">
                <i class="bi bi-check-lg" aria-hidden="true"></i> Approve
              </button>
            </form>
            <form method="post" action="principal-cf" data-confirm="Turn down <?= e($srcPrincipal['full_name']) ?>?">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $srcPrincipalId ?>">
              <button type="submit" name="action" value="reject_principal" class="btn btn--ghost">Turn down</button>
            </form>
          </div>
        </div>
      <?php endif; ?>

      <div class="detail-block">
        <p class="detail-block__title">Identity &amp; Jurisdiction</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>Code</dt>
            <dd><strong><?= e($srcPrincipal['principal_code'] ?: 'None (pending approval)') ?></strong></dd>
          </div>
          <div class="detail-field">
            <dt>Company</dt>
            <dd><?= e($srcPrincipal['company'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Terms &amp; Conditions</dt>
            <dd>
              <?php if (!empty($srcPrincipal['terms_accepted'])): ?>
                <span class="pill pill--good"><i class="bi bi-check-circle"></i> Accepted</span>
                <span class="field-hint">Recorded <?= e(format_datetime($srcPrincipal['terms_accepted_at'] ?? '')) ?> (<?= e(partner_terms('principal_cf')['summary']) ?>)</span>
              <?php else: ?>
                <span class="pill pill--warning">Pending</span>
              <?php endif; ?>
            </dd>
          </div>
          <div class="detail-field">
            <dt>Status</dt>
            <dd><?= $srcPrincipal['is_active'] ? 'Active' : 'Switched off' ?></dd>
          </div>
        </dl>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">Contact &amp; Location</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>Email</dt>
            <dd><?= e($srcPrincipal['email'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Mobile</dt>
            <dd><?= e($srcPrincipal['mobile_number'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Address</dt>
            <dd><?= e($srcPrincipal['address'] ?: '—') ?>, <?= e($srcPrincipal['city'] ?: '—') ?>, <?= e($srcPrincipal['state'] ?: '—') ?> - <?= e($srcPrincipal['pin_code'] ?: '—') ?></dd>
          </div>
        </dl>
      </div>

      <div class="detail-block">
        <p class="detail-block__title">Banking &amp; Tax</p>
        <dl class="detail-fields">
          <div class="detail-field">
            <dt>PAN</dt>
            <dd><?= e($srcPrincipal['pan_number'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>GST</dt>
            <dd><?= e($srcPrincipal['gst_number'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>Bank &amp; Account</dt>
            <dd><?= e($srcPrincipal['bank_name'] ?: '—') ?> &bull; <?= e($srcPrincipal['bank_account'] ?: '—') ?></dd>
          </div>
          <div class="detail-field">
            <dt>IFSC &amp; UPI</dt>
            <dd><?= e($srcPrincipal['bank_ifsc'] ?: '—') ?> &bull; <?= e($srcPrincipal['upi_id'] ?: '—') ?></dd>
          </div>
        </dl>
      </div>
    </section>

    <section class="detail-panel" data-panel="1" role="tabpanel">
      <div class="detail-block">
        <p class="detail-block__title">Assigned Zonal C&amp;Fs</p>
        <?php if (!$srcZonals): ?>
          <p class="field-hint">No Zonal C&amp;F partners assigned under this Principal C&amp;F yet.</p>
        <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Zonal C&amp;F</th>
                <th>Code</th>
                <th>Location</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($srcZonals as $z): ?>
                <tr>
                  <td><strong><?= e($z['full_name']) ?></strong></td>
                  <td><?= e($z['zonal_code'] ?: 'Pending') ?></td>
                  <td><?= e($z['city'] ?: $z['state']) ?></td>
                  <td><?= $z['is_active'] ? 'Active' : 'Paused' ?></td>
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
