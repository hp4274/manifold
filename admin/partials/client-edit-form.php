<?php
/**
 * The client edit form, shared by the office, dealers and distributors.
 *
 * Only the middle of the page — each portal wraps it in its own chrome.
 *
 * Expects:
 *   $app        the application row
 *   $actor      ['type' => office|dealer|distributor, 'id' => ?int, 'label' => string]
 *   $error      a message, or ''
 *   $saved      how many fields were changed on the last save, or null
 *   $closed     why editing is shut, or ''
 *   $shared     other applications already on the posted email, if any
 *   $posted     what was typed, when a save came back with an error
 *   $backHref   where Cancel goes
 *   $backLabel  what that link is called
 *   $history    recent rows from client_edits
 */

declare(strict_types=1);

/* What to show in a box: whatever was typed if the save bounced, otherwise
   what is stored. A form that came back with an error must not quietly throw
   away the other twenty things somebody had just fixed. */
$value = static function (string $name) use ($app, $posted) {
    return $posted !== null && array_key_exists($name, $posted)
        ? (string) $posted[$name]
        : (string) ($app[$name] ?? '');
};
?>

<?php if ($saved !== null): ?>
  <p class="alert alert--ok">
    <i class="bi bi-check-circle" aria-hidden="true"></i>
    <?php if ($saved === 0): ?>
      Nothing was different, so nothing was changed.
    <?php else: ?>
      Saved — <?= (int) $saved ?> <?= $saved === 1 ? 'detail' : 'details' ?> updated.
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error">
    <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= e($error) ?>
  </p>
<?php endif; ?>

<?php if ($closed !== ''): ?>
  <p class="alert alert--warn">
    <i class="bi bi-lock" aria-hidden="true"></i> <?= e($closed) ?>
  </p>
<?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2><?= e((string) $app['full_name']) ?></h2>
      <span class="eyebrow">
        <?= e((string) $app['reference_code']) ?> ·
        <?= e(product_label((string) $app['product'])) ?> ·
        <?= e(status_label((string) $app['status'])) ?>
      </span>
    </div>
    <a class="btn btn--ghost btn--sm" href="<?= e($backHref) ?>"><?= e($backLabel) ?></a>
  </div>

  <?php if ($closed !== ''): ?>
    <p class="empty">There is nothing to change here any more.</p>
  <?php else: ?>

  <form method="post">
    <div class="panel__body">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">

      <?php /* Shown only when the posted address already belongs to another
               application. Ticking it is the person saying those two records are
               the same human being — because after this they share a portal. */ ?>
      <?php if ($shared !== []): ?>
        <div class="alert alert--warn">
          <p style="margin:0 0 8px">
            <i class="bi bi-people" aria-hidden="true"></i>
            <strong>That address already signs in to another application.</strong>
          </p>
          <p style="margin:0 0 8px">
            <?php foreach ($shared as $other): ?>
              <?= e((string) $other['reference_code']) ?> (<?= e((string) $other['full_name']) ?>)<br>
            <?php endforeach; ?>
          </p>
          <p style="margin:0 0 10px">
            Whoever opens the portal with this address will see this application and
            <?= count($shared) === 1 ? 'that one' : 'those' ?> together. That is right if it is the
            same person ordering more than once, and wrong if it is not.
          </p>
          <label class="field-consent" for="share_email">
            <input id="share_email" type="checkbox" name="share_email" value="1">
            <span>Yes — this is the same person, and they should see both.</span>
          </label>
        </div>
      <?php endif; ?>

      <?php foreach (client_fields() as $groupLabel => $fieldsInGroup): ?>
        <div class="detail-block client-edit__group">
          <p class="detail-block__title"><?= e($groupLabel) ?></p>

          <div class="form-grid">
            <?php foreach ($fieldsInGroup as $name => $spec): ?>
              <?php if ($spec['kind'] === 'mobile'): ?>
                <?php
                  [$storedCode, $storedRest] = client_split_mobile((string) ($app[$name] ?? ''));
                  $code = $posted !== null && array_key_exists($name . '_code', $posted)
                      ? (string) $posted[$name . '_code'] : $storedCode;
                  $rest = $posted !== null && array_key_exists($name, $posted)
                      ? (string) $posted[$name] : $storedRest;
                ?>
                <div class="field">
                  <label for="f-<?= e($name) ?>"><?= e($spec['label']) ?></label>
                  <div class="field-split">
                    <input name="<?= e($name) ?>_code" type="text" inputmode="numeric"
                           maxlength="4" value="<?= e($code) ?>" aria-label="Country code for <?= e($spec['label']) ?>"
                           style="max-width:80px">
                    <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="tel"
                           inputmode="numeric" maxlength="15" value="<?= e($rest) ?>">
                  </div>
                  <span class="field-hint">Country code, then the number without it. Empty clears it.</span>
                </div>

              <?php elseif ($spec['kind'] === 'yesno'): ?>
                <div class="field">
                  <label for="f-<?= e($name) ?>"><?= e($spec['label']) ?></label>
                  <select id="f-<?= e($name) ?>" name="<?= e($name) ?>">
                    <?php foreach (['' => 'Not said', 'yes' => 'Yes', 'no' => 'No'] as $option => $optionLabel): ?>
                      <option value="<?= e($option) ?>"
                        <?= $value($name) === $option ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

              <?php elseif ($spec['kind'] === 'bool'): ?>
                <div class="field">
                  <label class="field-consent" for="f-<?= e($name) ?>">
                    <input id="f-<?= e($name) ?>" type="checkbox" name="<?= e($name) ?>" value="1"
                      <?= $value($name) === '1' ? 'checked' : '' ?>>
                    <span><?= e($spec['label']) ?></span>
                  </label>
                </div>

              <?php else: ?>
                <?php
                  $type = $spec['kind'] === 'date' ? 'date'
                        : ($spec['kind'] === 'email' ? 'email'
                        : ($spec['kind'] === 'int' ? 'number' : 'text'));
                ?>
                <div class="field">
                  <label for="f-<?= e($name) ?>">
                    <?= e($spec['label']) ?><?= empty($spec['required']) ? '' : ' *' ?>
                  </label>
                  <input id="f-<?= e($name) ?>" name="<?= e($name) ?>" type="<?= $type ?>"
                         value="<?= e($value($name)) ?>"
                         <?= isset($spec['max']) ? 'maxlength="' . (int) $spec['max'] . '"' : '' ?>
                         <?= $spec['kind'] === 'int' ? 'min="0" step="1"' : '' ?>
                         <?= empty($spec['required']) ? '' : 'required' ?>>

                  <?php if ($name === 'email'): ?>
                    <span class="field-hint">
                      <i class="bi bi-key" aria-hidden="true"></i>
                      This is how they sign in to their portal. Change it and the old address stops
                      opening it — both addresses are emailed so nobody loses an account quietly.
                    </span>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="modal-x__foot">
      <span class="field-hint">
        Their order — status, what is owed, what has been paid and the documents they uploaded —
        is not touched here.
      </span>
      <a class="btn btn--ghost" href="<?= e($backHref) ?>">Cancel</a>
      <button type="submit" class="btn btn--primary">Save changes</button>
    </div>
  </form>

  <?php endif; ?>
</div>

<?php if ($history !== []): ?>
  <div class="panel">
    <div class="panel__head">
      <div class="panel__head-text">
        <h2>What has been changed</h2>
        <span class="eyebrow">every edit to this client, whoever made it</span>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>When</th><th>Field</th><th>Was</th><th>Now</th><th>By</th></tr></thead>
        <tbody>
          <?php foreach ($history as $row): ?>
            <?php $spec = client_field_specs()[$row['field']] ?? null; ?>
            <tr>
              <td><?= e(date('j M Y, H:i', strtotime((string) $row['changed_at']))) ?></td>
              <td><?= e($spec['label'] ?? (string) $row['field']) ?></td>
              <td><?= e(client_value_label((string) $row['field'], $row['old_value'])) ?></td>
              <td><?= e(client_value_label((string) $row['field'], $row['new_value'])) ?></td>
              <td><?= e((string) $row['actor_label']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
