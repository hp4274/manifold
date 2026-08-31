<?php
/**
 * Per-row actions. Applications get the payment flow, the other two forms keep
 * the simple accept / contact / reject set. Expects $rowType, $row, $returnUrl.
 */

declare(strict_types=1);

$isApplication = type_config($rowType)['table'] === 'applications';

/* An accepted enquiry or signup is settled — nothing left to decide on it. */
$isSettled = !$isApplication && $row['status'] === 'accepted';

/* Applications are decided in the Details drawer, where the receipt can be
   seen — the row only offers what makes sense at the stage it has reached.
   The other two forms keep their set until they are accepted. */
$actions = ($isApplication || $isSettled) ? [] : [
    'accepted'  => ['icon' => 'bi-check-lg',  'label' => 'Accept',  'class' => 'is-accept'],
    'contacted' => ['icon' => 'bi-telephone', 'label' => 'Contact', 'class' => 'is-contact'],
    'rejected'  => ['icon' => 'bi-x-lg',      'label' => 'Reject',  'class' => 'is-reject'],
];
?>
<div class="row-actions">
  <?php /* The one decision on an application that is not a payment: whether we
           take it on. Approving it emails the payment details and opens the
           applicant's portal, so it is worth its own pair of buttons here —
           this is the queue the office works through. */ ?>
  <?php if ($isApplication && $row['status'] === 'submitted'): ?>
    <?php /* Approving is the ordinary answer and it is undone by turning them
             down, so it does not stop to ask. Turning down still does. */ ?>
    <form method="post" action="status.php">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="<?= e($rowType) ?>">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
      <input type="hidden" name="status" value="booking_pending">

      <button type="submit" class="icon-btn is-accept" title="Approve — sends the payment email">
        <i class="bi bi-check-lg" aria-hidden="true"></i>
        <span class="visually-hidden">Approve submission <?= (int) $row['id'] ?></span>
      </button>
    </form>

    <form method="post" action="status.php"
          data-confirm="Turn down <?= e(record_title($rowType, $row)) ?>? Nothing is emailed and their portal stays shut.">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="<?= e($rowType) ?>">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
      <input type="hidden" name="status" value="rejected">

      <button type="submit" class="icon-btn is-reject" title="Turn this application down">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
        <span class="visually-hidden">Turn down submission <?= (int) $row['id'] ?></span>
      </button>
    </form>
  <?php endif; ?>

  <?php /* chasing an unpaid application is a one-click job, so it lives on the row */ ?>
  <?php if ($isApplication && in_array($row['status'], ['booking_pending', 'delivery_pending'], true)): ?>
    <form method="post" action="payment.php"
          data-confirm="Email a payment reminder to <?= e($row['email']) ?>?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remind">
      <input type="hidden" name="type" value="<?= e($rowType) ?>">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">

      <?php $sentCount = (int) ($row['reminder_count'] ?? 0); ?>
      <button type="submit" class="icon-btn is-remind"
              title="Send payment reminder — <?= $sentCount ?> sent<?= $sentCount > 0
                  ? ', last ' . format_datetime($row['reminded_at']) : '' ?>">
        <i class="bi bi-bell" aria-hidden="true"></i>
        <?php /* the count slides out of the button on hover */ ?>
        <span class="icon-btn__text" aria-hidden="true"><?= $sentCount ?> sent</span>
        <span class="visually-hidden">
          Send payment reminder for submission <?= (int) $row['id'] ?>, <?= $sentCount ?> sent so far
        </span>
      </button>
    </form>
  <?php endif; ?>

  <?php /* A receipt is sitting there waiting to be checked. Verifying it means
           looking at the proof, so this opens the record on its Payment tab
           rather than accepting blind from the list — the one thing the office
           must not be able to do by mis-clicking a row. */ ?>
  <?php if ($isApplication && in_array($row['status'], ['booking_review', 'delivery_review'], true)): ?>
    <button type="button" class="icon-btn is-review"
            data-drawer="detail-<?= e($rowType) ?>-<?= (int) $row['id'] ?>" data-tab-index="0"
            data-title="<?= e(record_title($rowType, $row)) ?>"
            data-code="<?= e((string) ($row['reference_code'] ?? '')) ?>"
            data-meta="<?= e(type_config($rowType)['label']) ?> · received
                       <?= e(format_datetime($row['created_at'])) ?>"
            data-status="<?= e($row['status']) ?>"
            data-status-label="<?= e(status_short((string) $row['status'])) ?>"
            title="<?= e(status_label((string) $row['status'])) ?> — open the receipt">
      <i class="bi bi-receipt" aria-hidden="true"></i>
      <span class="visually-hidden">
        Check the receipt on submission <?= (int) $row['id'] ?>
      </span>
    </button>
  <?php endif; ?>

  <?php /* Nothing is owed and nothing is waiting: a finished row carries
           Delete and nothing else — every other action would only be a way to
           undo something by accident. */ ?>

  <?php /* the Status column already says "accepted" — no need to repeat it here */ ?>
  <?php foreach ($actions as $status => $action): ?>
    <?php $isCurrent = $row['status'] === $status; ?>
    <form method="post" action="status.php">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="<?= e($rowType) ?>">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="return" value="<?= e($returnUrl) ?>">
      <input type="hidden" name="status" value="<?= e($status) ?>">

      <button type="submit"
              class="icon-btn <?= e($action['class']) ?><?= $isCurrent ? ' is-current' : '' ?>"
              title="<?= e($action['label']) ?><?= $isCurrent ? ' (current status)' : '' ?>"
              <?= $isCurrent ? 'disabled' : '' ?>>
        <i class="bi <?= e($action['icon']) ?>" aria-hidden="true"></i>
        <span class="visually-hidden"><?= e($action['label']) ?> submission <?= (int) $row['id'] ?></span>
      </button>
    </form>
  <?php endforeach; ?>

  <form method="post" action="delete.php" data-confirm="Delete submission #<?= (int) $row['id'] ?> permanently? This cannot be undone.">
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="<?= e($rowType) ?>">
    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
    <input type="hidden" name="return" value="<?= e($returnUrl) ?>">

    <button type="submit" class="icon-btn is-delete" title="Delete">
      <i class="bi bi-trash" aria-hidden="true"></i>
      <span class="visually-hidden">Delete submission <?= (int) $row['id'] ?></span>
    </button>
  </form>
</div>
