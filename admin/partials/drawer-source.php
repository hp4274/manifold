<?php
/**
 * The hidden markup one Details button opens.
 *
 * A full application has a dozen field groups, which is far too long to scroll,
 * so the groups become tabs across the top of the drawer and only one shows at
 * a time. Expects $srcType, $srcRow and $srcReturn.
 */

declare(strict_types=1);

$srcId     = (int) $srcRow['id'];
$srcGroups = field_groups($srcType);
$srcIsApp  = type_config($srcType)['table'] === 'applications';
$srcReturn = $srcReturn ?? './';

/* the payment panel is the first tab on an application */
$srcTabs = $srcIsApp ? ['Payment'] : [];

foreach (array_keys($srcGroups) as $groupLabel) {
    $srcTabs[] = $groupLabel;
}
?>
<div class="drawer-source" id="detail-<?= e($srcType) ?>-<?= $srcId ?>" hidden>

  <?php if ($srcIsApp && !empty($srcRow['low_stock_hold'])): ?>
    <div class="decide-bar decide-bar--refund" style="border-color:var(--critical);background:rgba(217,45,32,0.06);margin-bottom:14px">
      <div class="decide-bar__text">
        <p class="decide-bar__title" style="color:var(--critical)"><i class="bi bi-shield-exclamation"></i> Low-Stock Hold Active (&le; 100 units)</p>
        <p class="decide-bar__note">
          Seller stock is at or below the 100-unit threshold. Dual approval is required before fulfillment.<br>
          Manifold Office: <?= !empty($srcRow['low_stock_approved_by_office']) ? '<strong style="color:var(--good)">Approved</strong>' : '<span style="color:var(--critical)">Pending Approval</span>' ?> &bull;
          Principal C&amp;F: <?= !empty($srcRow['low_stock_approved_by_principal']) ? '<strong style="color:var(--good)">Approved</strong>' : '<span style="color:var(--critical)">Pending Approval</span>' ?>
        </p>
      </div>

      <?php
        /* The Principal C&F signs one half and the office signs the other, so the
           button offers the half belonging to whoever is reading, and goes once
           they have signed it. */
        $srcIsPrincipal = in_array($user['role'] ?? 'admin', ['principal_cf', 'cf'], true);
        $srcMyApproval  = $srcIsPrincipal ? 'low_stock_approved_by_principal' : 'low_stock_approved_by_office';
        $srcMyDesk      = $srcIsPrincipal ? 'Principal C&F' : 'Manifold Office';
      ?>
      <?php if (empty($srcRow[$srcMyApproval])): ?>
        <div class="decide-bar__actions">
          <form method="post" action="status.php"
                data-confirm="Grant <?= e($srcMyDesk) ?> low-stock approval for <?= e($srcRow['full_name']) ?>?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="approve_low_stock">
            <input type="hidden" name="id" value="<?= $srcId ?>">
            <input type="hidden" name="return" value="<?= e($srcReturn) ?>">
            <button type="submit" class="btn btn--primary">
              <i class="bi bi-shield-check" aria-hidden="true"></i> Approve as <?= e($srcMyDesk) ?>
            </button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php /* An application waiting on the office is waiting on one decision, and
           this is where somebody is when they have finished reading it. The
           same two answers as the row, at the point the reading ends. */ ?>
  <?php if ($srcIsApp && ($srcRow['status'] ?? '') === 'submitted'): ?>
    <div class="decide-bar">
      <div class="decide-bar__text">
        <p class="decide-bar__title">Waiting for your approval</p>
        <p class="decide-bar__note">
          Approving emails <?= e($srcRow['full_name']) ?> the payment details and opens their portal.
          Turning it down tells them nothing and leaves it shut.
        </p>
      </div>

      <div class="decide-bar__actions">
        <form method="post" action="status.php"
              data-confirm="Approve <?= e($srcRow['full_name']) ?>? They are emailed the payment details and their portal opens.">
          <?= csrf_field() ?>
          <input type="hidden" name="type" value="<?= e($srcType) ?>">
          <input type="hidden" name="id" value="<?= $srcId ?>">
          <input type="hidden" name="return" value="<?= e($srcReturn) ?>">
          <input type="hidden" name="status" value="booking_pending">
          <button type="submit" class="btn btn--primary">
            <i class="bi bi-check-lg" aria-hidden="true"></i> Approve
          </button>
        </form>

        <form method="post" action="status.php"
              data-confirm="Turn down <?= e($srcRow['full_name']) ?>? Nothing is emailed and their portal stays shut.">
          <?= csrf_field() ?>
          <input type="hidden" name="type" value="<?= e($srcType) ?>">
          <input type="hidden" name="id" value="<?= $srcId ?>">
          <input type="hidden" name="return" value="<?= e($srcReturn) ?>">
          <input type="hidden" name="status" value="rejected">
          <button type="submit" class="btn btn--ghost">Turn down</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php /* The client answered the delivery question with "cancel", so the money
           they have already sent has to go back. Same shape as the approval
           bar above, because it is the same kind of thing: one decision the
           office has to make, at the top of what it is deciding on. */ ?>
  <?php if ($srcIsApp && ($srcRow['status'] ?? '') === 'cancelled'): ?>
    <?php $srcRefund = payment_totals($srcRow); ?>
    <div class="decide-bar decide-bar--refund">
      <div class="decide-bar__text">
        <p class="decide-bar__title">
          Refund requested — <?= e(money((float) $srcRefund['paid'])) ?>
        </p>
        <p class="decide-bar__note">
          <?= e($srcRow['full_name']) ?> cancelled on
          <?= e(format_datetime((string) ($srcRow['delivery_choice_at'] ?? $srcRow['created_at']))) ?>,
          before the documents were verified. Transfer the money back to the account they paid from,
          then record it here — that emails them the confirmation and closes the order.
        </p>
      </div>

      <div class="decide-bar__actions">
        <form method="post" action="payment.php"
              data-confirm="Record the <?= e(money((float) $srcRefund['paid'])) ?> refund to <?= e($srcRow['full_name']) ?>? They are emailed that it is on its way.">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="refund">
          <input type="hidden" name="type" value="<?= e($srcType) ?>">
          <input type="hidden" name="id" value="<?= $srcId ?>">
          <input type="hidden" name="return" value="<?= e($srcReturn) ?>">
          <button type="submit" class="btn btn--primary">
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Refund sent
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php /* Nothing left to do on a refunded order, but the drawer still has to
           say what happened to the money it is showing as paid. */ ?>
  <?php if ($srcIsApp && ($srcRow['status'] ?? '') === 'refunded'): ?>
    <p class="drawer-closed">
      <i class="bi bi-check-circle" aria-hidden="true"></i>
      Refunded in full and closed. The payments below stay on the record as they were.
    </p>
  <?php endif; ?>

  <?php /* The drawer reads; correcting what it shows is a page of its own, so
           that an edit is a deliberate act with a form and an audit trail behind
           it rather than something done in passing. */ ?>
  <?php if ($srcIsApp): ?>
    <p class="drawer__edit">
      <a class="btn btn--ghost btn--sm" href="client-edit?id=<?= $srcId ?>">
        <i class="bi bi-pencil" aria-hidden="true"></i>
        Edit <?= e((string) $srcRow['full_name']) ?>'s details
      </a>
    </p>
  <?php endif; ?>

  <?php if (count($srcTabs) > 1): ?>
    <nav class="detail-tabs" role="tablist" aria-label="Sections">
      <?php foreach ($srcTabs as $i => $tab): ?>
        <button type="button" class="detail-tab<?= $i === 0 ? ' is-active' : '' ?>"
                data-tab="<?= $i ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
          <?= $tab ?>
        </button>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <div class="detail-panels">
    <?php $srcIndex = 0; ?>

    <?php if ($srcIsApp): ?>
      <section class="detail-panel is-active" data-panel="0" role="tabpanel">
        <?php require __DIR__ . '/payment-panel.php'; ?>
      </section>
      <?php $srcIndex = 1; ?>
    <?php endif; ?>

    <?php foreach ($srcGroups as $tabLabel => $sections): ?>
      <section class="detail-panel<?= $srcIndex === 0 ? ' is-active' : '' ?>"
               data-panel="<?= $srcIndex ?>" role="tabpanel">
        <?php foreach ($sections as $sectionLabel => $fields): ?>
          <div class="detail-block">
            <p class="detail-block__title"><?= $sectionLabel ?></p>
            <dl class="detail-fields">
              <?php foreach ($fields as $key => $label): ?>
                <?php if (!array_key_exists($key, $srcRow)) { continue; } ?>
                <?php /* each pair is wrapped, so a multi-column grid cannot split them */ ?>
                <div class="detail-field">
                  <dt><?= e($label) ?></dt>
                  <dd><?= render_value($key, $srcRow[$key]) ?></dd>
                </div>
              <?php endforeach; ?>
            </dl>
          </div>
        <?php endforeach; ?>
      </section>
      <?php $srcIndex++; ?>
    <?php endforeach; ?>
  </div>

  <?php /* An enquiry is answered in a new tab: Gmail's web compose, with the sender's
           address, a subject and a greeting already filled in. A mailto: link did
           nothing on machines with no mail app registered. */ ?>
  <?php if ($srcType === 'contact' && filter_var((string) $srcRow['email'], FILTER_VALIDATE_EMAIL)): ?>
    <div class="drawer__reply">
      <a class="btn btn--primary" href="<?= e(enquiry_reply_url($srcRow)) ?>" target="_blank" rel="noopener">
        <i class="bi bi-reply" aria-hidden="true"></i> Reply
      </a>
    </div>
  <?php endif; ?>
</div>
