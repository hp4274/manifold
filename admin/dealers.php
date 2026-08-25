<?php
/**
 * Dealers: everyone selling on our behalf, and the code each one hands out.
 *
 * The code is allocated once, when the dealer is added, and never changes —
 * it is printed on their link and quoted by every customer they bring in, so
 * rewriting it would orphan the sales already attributed to them.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Dealers';
$pageLead   = 'Who sells for us, the link they share, and what each of them is owed.';
$activeType = 'dealers';

$error = '';

/* carried across the redirect that follows every successful action */
$flash = (string) ($_SESSION['dealers_flash'] ?? '');
unset($_SESSION['dealers_flash']);

/** Finish an action: remember what happened, then reload as a plain GET. */
function dealers_done(string $message): void
{
    $_SESSION['dealers_flash'] = $message;

    header('Location: dealers.php');
    exit;
}

$editing         = null;
$isEdit          = false;
$openDealerModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? 'save');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        /* everything except the name is optional — a dealer is often added from
           a phone call and the paperwork follows later */
        $fields = ['full_name', 'company', 'email', 'mobile_number', 'alt_mobile_number',
                   'address', 'city', 'state', 'pin_code', 'pan_number', 'gst_number',
                   'bank_name', 'bank_account', 'bank_ifsc', 'upi_id', 'note'];

        /* PAN, GST and IFSC are issued in capitals and quoted that way on every
           form the office files, so they are stored that way whatever was typed.
           Done here rather than in the browser: a value that reaches the table
           has to be right even when the request did not come from our form. */
        $shout = ['pan_number', 'gst_number', 'bank_ifsc'];

        $values = [];

        foreach ($fields as $field) {
            $value = trim((string) ($_POST[$field] ?? ''));

            if (in_array($field, $shout, true)) {
                $value = mb_strtoupper($value);
            }

            $values[$field] = $value === '' ? null : mb_substr($value, 0, $field === 'note' ? 2000 : 190);
        }

        /* Each of these is a fixed-length code. Blank is fine — the paperwork
           often arrives later — but a half-typed one is not: it would be quoted
           on a transfer that then bounces. */
        $lengths = [
            'pan_number' => ['label' => 'PAN',  'length' => 10],
            'gst_number' => ['label' => 'GST',  'length' => 15],
            'bank_ifsc'  => ['label' => 'IFSC', 'length' => 11],
        ];

        foreach ($lengths as $field => $rule) {
            $value = (string) ($values[$field] ?? '');

            if ($value === '' || preg_match('/^[A-Z0-9]{' . $rule['length'] . '}$/', $value)) {
                continue;
            }

            $error = $rule['label'] . ' has to be ' . $rule['length']
                . ' letters and digits, with nothing in between — you gave '
                . mb_strlen($value) . '.';
            break;
        }

        if ($error !== '') {
            $openDealerModal = true;
        } elseif ($values['full_name'] === null) {
            $error = 'A dealer needs a name.';
        } elseif ($values['email'] !== null && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address does not look right.';
        } elseif ($id > 0) {
            $set = implode(' = ?, ', $fields) . ' = ?';
            db()->prepare('UPDATE dealers SET ' . $set . ' WHERE id = ?')
                ->execute([...array_values($values), $id]);

            dealers_done('Dealer updated.');
        } else {
            $values['dealer_code'] = make_dealer_code();
            $values['created_by']  = (int) $user['id'];

            $names        = array_keys($values);
            $placeholders = implode(', ', array_fill(0, count($names), '?'));

            db()->prepare('INSERT INTO dealers (`' . implode('`, `', $names) . '`) VALUES (' . $placeholders . ')')
                ->execute(array_values($values));

            dealers_done($values['full_name'] . ' added, with code ' . $values['dealer_code'] . '.');
        }
    } elseif ($action === 'toggle') {
        db()->prepare('UPDATE dealers SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);

        $now = db()->prepare('SELECT full_name, is_active FROM dealers WHERE id = ?');
        $now->execute([$id]);
        $dealer = $now->fetch() ?: ['full_name' => 'That dealer', 'is_active' => 0];

        dealers_done($dealer['is_active']
            ? $dealer['full_name'] . ' is active again — their code works.'
            : $dealer['full_name'] . ' is switched off. Their code no longer books commission.');
    } elseif ($action === 'payout') {
        $amount = str_replace(',', '', trim((string) ($_POST['amount'] ?? '')));
        $note   = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255);
        $dealer = dealer_by_id($id);

        if (!$dealer) {
            $error = 'That dealer no longer exists.';
        } elseif (!is_numeric($amount) || (float) $amount <= 0) {
            $error = 'Enter the amount transferred, greater than zero.';
        } else {
            db()->prepare('INSERT INTO dealer_payouts (dealer_id, amount, note, paid_by) VALUES (?, ?, ?, ?)')
                ->execute([$id, (float) $amount, $note !== '' ? $note : null, (int) $user['id']]);

            dealers_done(money((float) $amount) . ' recorded against ' . $dealer['full_name'] . '.');
        }
    } elseif ($action === 'payout_delete') {
        /* a mistyped amount has to be removable, or the running total lies for good */
        db()->prepare('DELETE FROM dealer_payouts WHERE id = ? AND dealer_id = ?')
            ->execute([(int) ($_POST['payout_id'] ?? 0), $id]);

        dealers_done('Payout removed.');
    } elseif ($action === 'delete') {
        /* the sales stay: the foreign key nulls dealer_id rather than removing
           applications, so the customers are never lost with the dealer */
        db()->prepare('DELETE FROM dealers WHERE id = ?')->execute([$id]);

        dealers_done('Dealer deleted. Their customers keep their applications.');
    } else {
        $error = 'Unknown action.';
    }

    /* nothing was saved: reopen the dialog on what was typed, not on a blank
       form — one wrong character should not cost sixteen fields */
    if ($error !== '' && $action === 'save') {
        $existing        = $id > 0 ? dealer_by_id($id) : null;
        $editing         = $values + ['id' => $id, 'dealer_code' => $existing['dealer_code'] ?? ''];
        $isEdit          = $id > 0;
        $openDealerModal = true;
    }
}

if (($_GET['edit'] ?? '') !== '') {
    $editing         = dealer_by_id((int) $_GET['edit']);
    $isEdit          = $editing !== null;
    $openDealerModal = $isEdit;
}

/* one query for the list, one per dealer for the money — the list is short and
   staying with dealer_totals() keeps a single definition of what is owed */
$dealers = db()->query(
    'SELECT * FROM dealers ORDER BY is_active DESC, full_name'
)->fetchAll();

$totals  = ['earned' => 0.0, 'paid' => 0.0, 'remaining' => 0.0, 'sales' => 0];

foreach ($dealers as $i => $dealer) {
    $money = dealer_totals((int) $dealer['id']);
    $dealers[$i]['totals'] = $money;

    $totals['earned']    += $money['earned'];
    $totals['paid']      += $money['paid'];
    $totals['remaining'] += $money['remaining'];
    $totals['sales']     += $money['confirmed'];
}

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
    <span class="eyebrow">Confirmed sales</span>
    <strong><?= (int) $totals['sales'] ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">customers who have paid their booking</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Commission earned</span>
    <strong><?= e(money($totals['earned'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">at <?= e(money(dealer_commission())) ?> a unit</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Paid out so far</span>
    <strong><?= e(money($totals['paid'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">across every transfer recorded</span>
    </span>
  </span>
  <span class="tile">
    <span class="eyebrow">Still owed</span>
    <strong><?= e(money($totals['remaining'])) ?></strong>
    <span class="tile__stats">
      <span class="tile__stat">earned but not yet transferred</span>
    </span>
  </span>
</div>

<div class="panel">
  <div class="panel__head">
    <div class="panel__head-text">
      <h2>Dealers</h2>
      <span class="eyebrow"><?= count($dealers) ?> in total</span>
    </div>
    <button type="button" class="btn-add" data-modal-open="dealerModal">
      <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a dealer
    </button>
  </div>

  <?php if (!$dealers): ?>
    <p class="empty">No dealers yet. Add one and they get a code to share.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--dealers">
        <?php /* fixed layout, so the columns need telling how to share the width.
                 Action carries three icons and the Details button, which is why
                 it takes the largest share. */ ?>
        <colgroup>
          <col style="width:21%">
          <col style="width:12%">
          <col style="width:19%">
          <col style="width:7%">
          <col style="width:11%">
          <col style="width:16%">
          <col style="width:14%">
        </colgroup>
        <thead>
          <tr>
            <th>Dealer</th>
            <th>Code</th>
            <th>Link</th>
            <th>Sales</th>
            <th>Still owed</th>
            <th>Action</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dealers as $dealer): ?>
            <?php $dealerId = (int) $dealer['id']; ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <strong><?= e($dealer['full_name']) ?></strong>
                  <?php if ($dealer['company']): ?>
                    <span class="cell-sub"><?= e($dealer['company']) ?></span>
                  <?php endif; ?>
                  <span class="cell-sub">
                    <?php if ($dealer['mobile_number']): ?><?= e($dealer['mobile_number']) ?> · <?php endif; ?>
                    <?= e($dealer['email'] ?: 'no email') ?>
                  </span>
                  <?php if (!$dealer['is_active']): ?>
                    <span class="pill pill--rejected">Switched off</span>
                  <?php endif; ?>
                </div>
              </td>
              <td><span class="drawer__code"><?= e($dealer['dealer_code']) ?></span></td>
              <td>
                <div class="copy-links">
                  <?php /* the full URLs, spelled out, are a click away under Details */ ?>
                  <?php foreach (['stove' => 'Stove', 'tuktuk' => 'TukTuk'] as $product => $label): ?>
                    <?php $link = referral_link((string) $dealer['dealer_code'], $product); ?>
                    <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($link) ?>"
                            title="Copy the <?= e($label) ?> apply link">
                      <i class="bi bi-link-45deg" aria-hidden="true"></i> <?= e($label) ?>
                      <span class="visually-hidden">apply link for <?= e($dealer['full_name']) ?></span>
                    </button>
                  <?php endforeach; ?>
                </div>
              </td>
              <td class="td-amount"><strong><?= (int) $dealer['totals']['confirmed'] ?></strong></td>
              <td class="td-amount"><strong><?= e(money($dealer['totals']['remaining'])) ?></strong></td>
              <td>
                <div class="row-actions">
                  <button type="button" class="icon-btn is-accept"
                          data-drawer="detail-dealer-<?= $dealerId ?>" data-tab-index="2"
                          data-title="<?= e($dealer['full_name']) ?>"
                          data-code="<?= e($dealer['dealer_code']) ?>"
                          data-meta="Dealer · added <?= e(format_datetime($dealer['created_at'])) ?>"
                          data-status="<?= $dealer['is_active'] ? 'accepted' : 'rejected' ?>"
                          data-status-label="<?= $dealer['is_active'] ? 'Active' : 'Stopped' ?>"
                          title="Record a payout — <?= e(money($dealer['totals']['remaining'])) ?> owed">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i>
                    <span class="visually-hidden">Record a payout for <?= e($dealer['full_name']) ?></span>
                  </button>

                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $dealerId ?>">
                    <button type="submit" name="action" value="toggle"
                            class="icon-btn <?= $dealer['is_active'] ? 'is-reject' : 'is-accept' ?>"
                            title="<?= $dealer['is_active']
                                ? 'Stop this dealer — their code stops booking commission'
                                : 'Start this dealer again' ?>">
                      <i class="bi <?= $dealer['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"
                         aria-hidden="true"></i>
                      <span class="visually-hidden">
                        <?= $dealer['is_active'] ? 'Stop' : 'Start' ?> <?= e($dealer['full_name']) ?>
                      </span>
                    </button>
                  </form>

                  <form method="post"
                        data-confirm="Delete <?= e($dealer['full_name']) ?>? Their customers keep their applications.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $dealerId ?>">
                    <button type="submit" name="action" value="delete" class="icon-btn is-delete" title="Delete">
                      <i class="bi bi-trash" aria-hidden="true"></i>
                      <span class="visually-hidden">Delete <?= e($dealer['full_name']) ?></span>
                    </button>
                  </form>

                </div>
              </td>
              <td class="td-actions">
                <button type="button" class="row-toggle" data-drawer="detail-dealer-<?= $dealerId ?>"
                        data-title="<?= e($dealer['full_name']) ?>"
                        data-code="<?= e($dealer['dealer_code']) ?>"
                        data-meta="Dealer · added <?= e(format_datetime($dealer['created_at'])) ?>"
                        data-status="<?= $dealer['is_active'] ? 'accepted' : 'rejected' ?>"
                        data-status-label="<?= $dealer['is_active'] ? 'Active' : 'Stopped' ?>">
                  Details <i class="bi bi-chevron-right" aria-hidden="true"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ============ detail drawers ============ -->
<?php foreach ($dealers as $dealer): ?>
  <?php $srcDealer = $dealer; require __DIR__ . '/partials/dealer-source.php'; ?>
<?php endforeach; ?>

<?php require __DIR__ . '/partials/drawer.php'; ?>

<!-- the dealer form lives in a dialog, opened by the + on the list above -->
<div class="modal-x<?= $openDealerModal ? ' is-open' : '' ?>" id="dealerModal" role="dialog" aria-modal="true"
     aria-labelledby="dealerModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card modal-x__card--wide">
    <div class="modal-x__head">
      <h2 id="dealerModalTitle"><?= $isEdit ? 'Edit dealer' : 'Add a dealer' ?></h2>
      <button type="button" class="modal-x__close" data-modal-close aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <form method="post">
      <div class="modal-x__body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $isEdit ? (int) $editing['id'] : 0 ?>">

        <?php /* A dealer is usually added mid-phone-call. The name is the only
                 thing the office has for certain, so it is the only thing this
                 form insists on — the rest is whatever the paperwork says. */ ?>
        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Who they are</h3>
            <span class="form-section__note">Name required · the rest can follow later</span>
          </div>

          <div class="field field--primary">
            <label for="dealer_full_name">Full name<span class="field__req" aria-hidden="true">*</span></label>
            <input id="dealer_full_name" name="full_name" type="text" maxlength="160" required
                   autocomplete="off" value="<?= e($editing['full_name'] ?? '') ?>">
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="dealer_company">Company</label>
              <input id="dealer_company" name="company" type="text" maxlength="160"
                     value="<?= e($editing['company'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_email">Email</label>
              <input id="dealer_email" name="email" type="email" maxlength="190"
                     value="<?= e($editing['email'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_mobile">Mobile</label>
              <input id="dealer_mobile" name="mobile_number" type="text" maxlength="30"
                     value="<?= e($editing['mobile_number'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_alt_mobile">Alternative mobile</label>
              <input id="dealer_alt_mobile" name="alt_mobile_number" type="text" maxlength="30"
                     value="<?= e($editing['alt_mobile_number'] ?? '') ?>">
            </div>
          </div>

          <div class="code-preview">
            <span class="code-preview__chip<?= $isEdit ? '' : ' code-preview__chip--pending' ?>">
              <?= $isEdit ? e($editing['dealer_code']) : 'MD••••••' ?>
            </span>
            <span class="code-preview__text">
              <?= $isEdit
                  ? 'Their code. Issued when they were added and never changed since — every sale they have made quotes it.'
                  : 'A code is issued when you save, and never changes afterwards. It goes in the link they share.' ?>
            </span>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Where they are</h3>
          </div>

          <div class="form-grid">
            <div class="field field--wide">
              <label for="dealer_address">Address</label>
              <input id="dealer_address" name="address" type="text" maxlength="255"
                     value="<?= e($editing['address'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_city">City</label>
              <input id="dealer_city" name="city" type="text" maxlength="120"
                     value="<?= e($editing['city'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_state">State</label>
              <input id="dealer_state" name="state" type="text" maxlength="120"
                     value="<?= e($editing['state'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_pin">Pin code</label>
              <input id="dealer_pin" name="pin_code" type="text" maxlength="20"
                     value="<?= e($editing['pin_code'] ?? '') ?>">
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Where the commission goes</h3>
            <span class="form-section__note">Needed before the first payout</span>
          </div>

          <div class="form-grid">
            <div class="field">
              <label for="dealer_pan">PAN</label>
              <input class="field-shout" id="dealer_pan" name="pan_number" type="text" maxlength="10"
                     pattern="[A-Za-z0-9]{10}" title="10 letters and digits"
                     placeholder="ABCDE1234F" value="<?= e($editing['pan_number'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_gst">GST</label>
              <input class="field-shout" id="dealer_gst" name="gst_number" type="text" maxlength="15"
                     pattern="[A-Za-z0-9]{15}" title="15 letters and digits"
                     placeholder="24ABCDE1234F1Z5" value="<?= e($editing['gst_number'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_bank">Bank</label>
              <input id="dealer_bank" name="bank_name" type="text" maxlength="120"
                     value="<?= e($editing['bank_name'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_account">Account number</label>
              <input id="dealer_account" name="bank_account" type="text" maxlength="60"
                     value="<?= e($editing['bank_account'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_ifsc">IFSC</label>
              <input class="field-shout" id="dealer_ifsc" name="bank_ifsc" type="text" maxlength="11"
                     pattern="[A-Za-z0-9]{11}" title="11 letters and digits"
                     placeholder="HDFC0001234" value="<?= e($editing['bank_ifsc'] ?? '') ?>">
            </div>

            <div class="field">
              <label for="dealer_upi">UPI ID</label>
              <input id="dealer_upi" name="upi_id" type="text" maxlength="120"
                     value="<?= e($editing['upi_id'] ?? '') ?>">
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section__head">
            <h3 class="form-section__title">Note</h3>
            <span class="form-section__note">Only the office sees this</span>
          </div>

          <div class="field">
            <label class="visually-hidden" for="dealer_note">Note</label>
            <textarea id="dealer_note" name="note" rows="3"
                      placeholder="Which area they cover, who introduced them, anything worth knowing on the next call."><?= e($editing['note'] ?? '') ?></textarea>
          </div>
        </section>
      </div>

      <div class="modal-x__foot">
        <?php if (!$isEdit): ?>
          <span class="field-hint">Everything except the name can be added later.</span>
        <?php endif; ?>
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary"><?= $isEdit ? 'Save dealer' : 'Create dealer' ?></button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
