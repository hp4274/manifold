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
          after the documents were verified. Transfer the money back to the account they paid from,
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
</div>
