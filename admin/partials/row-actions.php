<?php
/**
 * Per-row actions. Applications get the payment flow, the other two forms keep
 * the simple accept / contact / reject set. Expects $rowType, $row, $returnUrl.
 */

declare(strict_types=1);

$isApplication = type_config($rowType)['table'] === 'applications';

/* A completed application is finished — nothing further to set on it. */
$isFinished = $isApplication && $row['status'] === 'complete';

$actions = $isApplication
    ? [
        'pending'         => ['icon' => 'bi-hourglass-split', 'label' => 'Mark under review', 'class' => 'is-review'],
        'confirmed'       => ['icon' => 'bi-check-lg',        'label' => 'Confirm and email payment details', 'class' => 'is-accept'],
        'complete'        => ['icon' => 'bi-patch-check',     'label' => 'Verify payment and complete', 'class' => 'is-complete'],
        'rejected'        => ['icon' => 'bi-x-lg',            'label' => 'Reject', 'class' => 'is-reject'],
    ]
    : [
        'accepted'  => ['icon' => 'bi-check-lg',  'label' => 'Accept',  'class' => 'is-accept'],
        'contacted' => ['icon' => 'bi-telephone', 'label' => 'Contact', 'class' => 'is-contact'],
        'rejected'  => ['icon' => 'bi-x-lg',      'label' => 'Reject',  'class' => 'is-reject'],
    ];

$confirmFor = [
    'confirmed' => 'Confirm this application? The applicant is emailed the payment QR code straight away.',
    'complete'  => 'Mark payment verified and the application complete? The applicant is emailed.',
];
?>
<div class="row-actions">
  <?php if ($isFinished): ?>
    <span class="row-done" title="This application is complete">
      <i class="bi bi-lock" aria-hidden="true"></i> Done
    </span>
  <?php endif; ?>

  <?php foreach ($isFinished ? [] : $actions as $status => $action): ?>
    <?php $isCurrent = $row['status'] === $status; ?>
    <form method="post" action="status.php"
          <?= isset($confirmFor[$status]) ? 'data-confirm="' . e($confirmFor[$status]) . '"' : '' ?>>
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
