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
   seen — the row only offers delete. The other two forms keep their set until
   they are accepted. */
$actions = ($isApplication || $isSettled) ? [] : [
    'accepted'  => ['icon' => 'bi-check-lg',  'label' => 'Accept',  'class' => 'is-accept'],
    'contacted' => ['icon' => 'bi-telephone', 'label' => 'Contact', 'class' => 'is-contact'],
    'rejected'  => ['icon' => 'bi-x-lg',      'label' => 'Reject',  'class' => 'is-reject'],
];
?>
<div class="row-actions">
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
