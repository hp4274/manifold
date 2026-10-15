<?php
/**
 * Add or edit one partner under this C&F: a zonal C&F (principal only) or a
 * distributor (principal or zonal).
 *
 * A new partner starts "with the office" — no working code, no sign-in — until
 * the office approves them, the same hold a distributor's new dealer sits in.
 * An existing one is fetched by id *and* ownership, so a guessed id opens nobody
 * else's. The code, the approval and who they answer to (for a zonal) stay with
 * the office; only details, the commission and — for a principal — which of
 * their zonals a distributor sits under can change here.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

[$tier, $me] = require_cf_partner();

$kind = (string) ($_GET['kind'] ?? $_POST['kind'] ?? '');
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if (!in_array($kind, ['zonal_cf', 'distributor'], true) || ($kind === 'zonal_cf' && $tier !== 'principal_cf')) {
    header('Location: ./?as=' . $tier);
    exit;
}

$isZonal = $kind === 'zonal_cf';
$list    = $isZonal ? 'zonals' : 'distributors';
$table   = $isZonal ? 'zonal_cf' : 'distributors';
$noun    = $isZonal ? 'Zonal C&F' : 'distributor';
$row     = null;

if ($id > 0) {
    $row = cf_owned($tier, $me, $kind, $id);

    if (!$row) {
        cf_done('That ' . $noun . ' is not one of yours.', $list, $tier);
    }
}

$ceiling = cf_own_rate($tier, $me);
$zonals  = $tier === 'principal_cf' ? cf_zonals($tier, $me) : [];

$pageTitle = $row ? 'Edit ' . $row['full_name'] : 'Add a ' . $noun;
$pageLead  = $row ? 'Their details and their commission.' : 'They start with the office, who check the details and issue the code.';
$activeNav = $list;

$error   = '';
$editing = $row;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    [$values, $error] = partner_values($_POST, $kind, $id);
    [$rate, $rateError] = cf_rate_parse((string) ($_POST['commission_rate'] ?? ''), $ceiling);
    $error = $error ?: $rateError;

    /* which zonal a distributor sits under: a zonal's own, or one of a principal's */
    $zonalId = $tier === 'zonal_cf' ? (int) $me['id'] : (int) ($_POST['zonal_cf_id'] ?? 0);

    if ($error === '' && !$isZonal && $tier === 'principal_cf' && !cf_owned($tier, $me, 'zonal_cf', $zonalId)) {
        $error = 'Pick which of your Zonal C&Fs this distributor sits under.';
    }

    /* How many children this one may hold. Anybody reaching this form already
       sits above the row (cf_owned), so the number is theirs to set — never
       their own, which no desk page offers. */
    [$cap, $capError] = child_cap_parse($_POST['max_children'] ?? '');
    $error = $error ?: $capError;
    $values['max_children'] = $cap;

    /* a new partner, or a distributor moved to another zonal, takes a place there */
    if ($error === '' && $isZonal && $id === 0) {
        $error = child_cap_error('principal_cf', (int) $me['id']);
    } elseif ($error === '' && !$isZonal && (int) ($row['zonal_cf_id'] ?? 0) !== $zonalId) {
        $error = child_cap_error('zonal_cf', $zonalId, $id);
    }

    if ($error === '') {
        $values['commission_rate'] = $rate;

        if ($id > 0) {
            if (!$isZonal) {
                $values['zonal_cf_id'] = $zonalId;
            }

            db()->prepare('UPDATE ' . $table . ' SET ' . implode(' = ?, ', array_keys($values)) . ' = ? WHERE id = ?')
                ->execute([...array_values($values), $id]);

            cf_done($values['full_name'] . ' is saved.', $list, $tier);
        }

        $values['approval_status'] = 'pending';

        if ($isZonal) {
            $values['principal_cf_id'] = (int) $me['id'];
        } else {
            $values['zonal_cf_id']      = $zonalId;
            $values['distributor_code'] = make_distributor_code();
        }

        db()->prepare('INSERT INTO ' . $table . ' (`' . implode('`, `', array_keys($values)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($values), '?')) . ')')->execute(array_values($values));

        cf_done($values['full_name'] . ' has been sent to the office. They can sign in and earn once it is approved.', $list, $tier);
    }

    /* nothing saved: come back to what was typed */
    $editing = $values + ($row ?? []);
}

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($error !== ''): ?><p class="alert alert--error"><?= e($error) ?></p><?php endif; ?>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2><?= e($row ? $row['full_name'] : 'A ' . $noun . ' under you') ?></h2>
      <?php if ($row): ?>
        <span class="eyebrow"><?= e(cf_status($row)[0]) ?> · their code and approval are the office's to change</span>
      <?php endif; ?>
    </div>
    <a class="btn btn--ghost btn--sm" href="<?= e($list . '?as=' . $tier) ?>">Back</a>
  </div>

  <form method="post" action="partner-form?as=<?= e($tier) ?>">
    <div class="panel__body">
      <?= csrf_field() ?>
      <input type="hidden" name="kind" value="<?= e($kind) ?>">
      <input type="hidden" name="id" value="<?= (int) $id ?>">

      <?php if (!$isZonal && $tier === 'principal_cf'): ?>
        <section class="form-section">
          <div class="form-section__head"><h3 class="form-section__title">Sits under</h3></div>
          <div class="field">
            <label for="zonal_cf_id">Zonal C&amp;F<span class="field__req" aria-hidden="true">*</span></label>
            <select id="zonal_cf_id" name="zonal_cf_id" required>
              <option value="">Pick one</option>
              <?php foreach ($zonals as $z): ?>
                <option value="<?= (int) $z['id'] ?>" <?= (int) ($editing['zonal_cf_id'] ?? $_POST['zonal_cf_id'] ?? 0) === (int) $z['id'] ? 'selected' : '' ?>>
                  <?= e($z['full_name']) ?><?= $z['zonal_code'] ? ' · ' . e($z['zonal_code']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </section>
      <?php endif; ?>

      <section class="form-section">
        <div class="form-section__head">
          <h3 class="form-section__title">Commission</h3>
          <span class="form-section__note">percent of the sale, excluding GST</span>
        </div>
        <div class="field">
          <label for="commission_rate">Rate for this <?= e($noun) ?></label>
          <input id="commission_rate" name="commission_rate" type="number" step="0.01" min="0"
                 max="<?= e(number_format($ceiling, 2, '.', '')) ?>"
                 value="<?= isset($editing['commission_rate']) && $editing['commission_rate'] !== null ? e(rtrim(rtrim((string) $editing['commission_rate'], '0'), '.')) : '' ?>"
                 placeholder="<?= e(rtrim(rtrim(number_format(commission_rate($kind), 2, '.', ''), '0'), '.')) ?>">
          <span class="field-hint">
            Leave blank for the standard <?= e(rate_short(commission_rate($kind))) ?>.
            Most you can set is your own <?= e(rate_short($ceiling)) ?>. New sales use it; sales already made keep theirs.
          </span>
        </div>
      </section>

      <?php
        $partnerKind   = $kind;
        $partnerCapEdit = true;
        $partnerEdit   = $editing;
        $partnerIsEdit = $row !== null;
        $partnerCode   = (string) ($row[$isZonal ? 'zonal_code' : 'distributor_code'] ?? '');
        require __DIR__ . '/../admin/partials/partner-fields.php';
      ?>
    </div>

    <div class="modal-x__foot">
      <a class="btn btn--ghost" href="<?= e($list . '?as=' . $tier) ?>">Cancel</a>
      <button type="submit" class="btn btn--primary"><?= $row ? 'Save changes' : 'Send to the office' ?></button>
    </div>
  </form>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
