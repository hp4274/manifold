<?php
/**
 * Applicant dashboard: the stage timeline per application, and the receipt
 * upload once an application has been confirmed.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$email = require_applicant();
$error = '';

/* An upload that fails belongs under the box it was chosen in, not at the top
   of a page that may hold two of them. This remembers which one, so the right
   box turns red and the other is left alone. */
$uploadError = '';
$uploadStage = '';

/**
 * Why an upload did not arrive, said plainly.
 *
 * A file over PHP's own limit never reaches the size check further down — the
 * request arrives with an error code and an empty temporary file instead — so
 * this is where "too large" has to be caught. Without it, a 12 MB receipt was
 * answered with "Choose the receipt file to upload", which is both wrong and
 * impossible to act on.
 */
function upload_error_message(int $code): string
{
    $limit = (int) (UPLOAD_MAX_BYTES / 1024 / 1024);

    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is larger than ' . $limit . ' MB. Send a smaller photo, or a PDF.';

        case UPLOAD_ERR_PARTIAL:
            return 'The upload stopped before it finished. Try it again.';

        case UPLOAD_ERR_NO_FILE:
            return 'Choose the receipt file to upload.';

        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 'We could not save that file. Try again, and tell us if it keeps happening.';
    }

    return 'That file did not upload. Try again.';
}
$done  = false;

/* ---------- receipt upload: one per stage, booking first ---------- */
/* A file past PHP's own post_max_size arrives with $_POST and $_FILES both
   empty — the request is thrown away before this script sees any of it. Left
   alone that reads as a missing CSRF token, so a receipt too big for the server
   would be answered with a security error rather than "too large". */
$postDiscarded = $_SERVER['REQUEST_METHOD'] === 'POST'
    && !$_POST
    && !$_FILES
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

if ($postDiscarded) {
    $uploadError = 'That file is larger than ' . (int) (UPLOAD_MAX_BYTES / 1024 / 1024)
        . ' MB. Send a smaller photo, or a PDF.';
    $uploadStage = 'booking';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $id    = (int) ($_POST['id'] ?? 0);
    $stage = (string) ($_POST['stage'] ?? '');

    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND email = ?');
    $stmt->execute([$id, $email]);
    $app = $stmt->fetch();

    $totals = $app ? payment_totals($app) : null;
    $due    = $totals !== null && in_array($stage, PAYMENT_STAGES, true) ? $totals['stages'][$stage] : null;

    /* ---------- go ahead, or cancel and take the money back ----------
       Asked once, after the documents are verified and before anything more is
       owed. Answering is the client's own decision, so it is theirs to make in
       the portal rather than something the office records for them. */
    $choice = (string) ($_POST['choice'] ?? '');

    if ($app && in_array($choice, ['continue', 'cancel'], true)) {
        if (($app['status'] ?? '') !== 'confirm_pending') {
            $error = 'That question has already been answered.';
        } else {
            db()->prepare(
                'UPDATE applications SET delivery_choice = ?, delivery_choice_at = NOW() WHERE id = ? AND email = ?'
            )->execute([$choice, $id, $email]);

            $status = sync_application_status($id);

            $fresh = db()->prepare('SELECT * FROM applications WHERE id = ?');
            $fresh->execute([$id]);
            $after = $fresh->fetch() ?: $app;

            after_response(static function () use ($after, $choice): void {
                $choice === 'cancel'
                    ? send_order_cancelled_email($after)
                    : send_delivery_open_email($after);

                send_delivery_choice_admin($after, $choice);
            });

            header('Location: status?choice=' . urlencode($choice) . '#app-' . $id);
            exit;
        }
    }

    if (!$app) {
        $error = 'That application could not be found.';
    } elseif ($due === null) {
        $error = 'That payment could not be matched to your application.';
    } elseif ($app['status'] === 'rejected') {
        $error = 'This application is not going ahead, so there is nothing to pay.';
    } elseif ($due['state'] === 'paid') {
        $error = 'Your ' . strtolower($due['label']) . ' is already verified.';
    } elseif ($due['state'] === 'checking') {
        $error = 'We are still checking the receipt you uploaded for the ' . strtolower($due['label']) . '.';
    } elseif ($due['state'] === 'locked') {
        $error = empty($app['docs_verified_at']) && payment_stage_settled((int) $app['id'], 'booking')
            ? 'The delivery payment opens once our finance team has verified your documents.'
            : 'The delivery payment opens once your booking payment has been verified.';
    } elseif (empty($_FILES['payment_proof'])
        || (int) $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        /* whatever went wrong, said in words the person can act on */
        $uploadError = upload_error_message(
            (int) ($_FILES['payment_proof']['error'] ?? UPLOAD_ERR_NO_FILE)
        );
        $uploadStage = $stage;
    } else {
        $file  = $_FILES['payment_proof'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);

        if ($file['size'] > UPLOAD_MAX_BYTES) {
            /* a file inside PHP's limit but outside ours */
            $uploadError = upload_error_message(UPLOAD_ERR_INI_SIZE);
            $uploadStage = $stage;
        } elseif (!isset(UPLOAD_ALLOWED_MIME[$mime])) {
            $uploadError = 'That is not a JPG, PNG, WebP or PDF.';
            $uploadStage = $stage;
        } else {
            if (!is_dir(PAYMENT_PROOF_DIR)) {
                mkdir(PAYMENT_PROOF_DIR, 0775, true);
            }

            $name = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . UPLOAD_ALLOWED_MIME[$mime];

            if (!move_uploaded_file($file['tmp_name'], PAYMENT_PROOF_DIR . '/' . $name)) {
                $uploadError = 'The upload did not complete. Try again.';
                $uploadStage = $stage;
            } else {
                /* the amount is the published one for this stage — the applicant
                   never types it, so a receipt can only ever be for what is owed */
                db()->prepare(
                    'INSERT INTO payments (application_id, stage, amount, reference, proof_path)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $id,
                    $stage,
                    $due['amount'],
                    mb_substr(trim((string) ($_POST['payment_reference'] ?? '')), 0, 120) ?: null,
                    $name,
                ]);

                /* keep the application row in step and tell the team */
                $app['status'] = sync_application_status($id);

                /* the client has uploaded their receipt and wants their page
                   back; telling the office can happen after they have it */
                after_response(static function () use ($app): void {
                    send_payment_received_admin($app);
                });

                header('Location: status?uploaded=' . $id . '&stage=' . urlencode($stage) . '#app-' . $id);
                exit;
            }
        }
    }
}

$applications = applications_for($email);
$uploadedId   = (int) ($_GET['uploaded'] ?? 0);
$uploadedStage = in_array((string) ($_GET['stage'] ?? ''), PAYMENT_STAGES, true)
    ? (string) $_GET['stage']
    : 'booking';
$pageTitle    = 'My applications';

require __DIR__ . '/partials/head.php';
?>

<section class="section portal">
  <div class="container-x">

    <?php /* the name off their own application, so the page opens by saying who
             it belongs to rather than only which mailbox opened it */ ?>
    <?php $who = $applications[0]['full_name'] ?? ''; ?>

    <p class="eyebrow eyebrow--rule">Signed in as <?= e($email) ?></p>
    <div class="section-head">
      <h1 class="section-title"><?= $who !== '' ? e($who) . '.' : 'My applications.' ?></h1>
      <p class="section-sub">Everything you have applied for, and what happens next at each stage.</p>
    </div>

    <?php if ($uploadedId > 0): ?>
      <p class="portal-alert portal-alert--ok">
        <?= e(payment_stage_label($uploadedStage)) ?> receipt received for application #<?= $uploadedId ?>.
        We verify payments within two working days.
      </p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <p class="portal-alert portal-alert--error"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (!$applications): ?>
      <div class="portal-card">
        <h2>Nothing here yet</h2>
        <p class="u-sub">No application is linked to <?= e($email) ?>. If you applied with a different address,
          <a href="./">sign in with that one</a> instead.</p>
        <p class="u-sub"><a class="link-arrow" href="../apply-stove">Apply for a stove</a> ·
          <a class="link-arrow" href="../apply-tuktuk">Apply for a TukTuk kit</a></p>
      </div>
    <?php endif; ?>

    <?php foreach ($applications as $app): ?>
      <?php
        $status  = (string) $app['status'];
        $stageIx = array_search($status, APPLICATION_STAGES, true);
        [$title, $copy] = stage_copy($status);
      ?>
      <article class="portal-app" id="app-<?= (int) $app['id'] ?>">

        <header class="portal-app__head">
          <div>
            <p class="portal-app__ref"><span>Booking number</span> <?= e($app['reference_code']) ?></p>
            <h2><?= e(product_name((string) $app['product'])) ?></h2>
            <p class="portal-app__meta">Applied <?= e(format_datetime($app['created_at'])) ?></p>

            <?php /* who this went through, named on the application it belongs
                     to rather than once at the top: a client with two orders may
                     have bought them from two different people */ ?>
            <?php $seller = sold_by($app); ?>

            <?php if ($seller): ?>
              <p class="portal-app__seller">
                <span class="portal-app__seller-kind"><?= e($seller['kind']) ?></span>
                <strong><?= e($seller['full_name']) ?></strong>
                <span class="portal-app__code"><?= e($seller['code']) ?></span>

                <?php if (!empty($seller['distributor_name'])): ?>
                  <span class="portal-app__under">
                    under <?= e($seller['distributor_name']) ?>
                    · <?= e($seller['distributor_code']) ?>
                  </span>
                <?php endif; ?>
              </p>
            <?php else: ?>
              <p class="portal-app__seller">
                <span class="portal-app__seller-kind">Bought from</span>
                <strong>Manifold Clean Energy</strong>
              </p>
            <?php endif; ?>
          </div>
          <span class="portal-status portal-status--<?= e($status) ?>"><?= e(status_label($status, 'applicant')) ?></span>
        </header>

        <?php if ($status === 'rejected'): ?>
          <p class="portal-alert portal-alert--error"><strong><?= e($title) ?>.</strong> <?= e($copy) ?></p>
        <?php else: ?>
          <ol class="portal-steps">
            <?php foreach (APPLICATION_STAGES as $i => $stage): ?>
              <?php
                $state = $stageIx === false ? 'todo' : ($i < $stageIx ? 'done' : ($i === $stageIx ? 'now' : 'todo'));

                /* the final stage is an end point, not a step in progress —
                   once it is reached it gets the tick like the others */
                if ($state === 'now' && $stage === 'complete') {
                    $state = 'done';
                }

                [$stageTitle] = stage_copy($stage);
              ?>
              <li class="portal-step is-<?= $state ?><?= $i === $stageIx ? ' is-current' : '' ?>">
                <span class="portal-step__dot" aria-hidden="true">
                  <?php if ($state === 'done'): ?><i class="bi bi-check-lg" aria-hidden="true"></i><?php endif; ?>
                </span>
                <span class="visually-hidden">
                  <?= $state === 'done' ? 'Completed:' : ($state === 'now' ? 'Current stage:' : 'Upcoming:') ?>
                </span>
                <span class="portal-step__label"><?= e($stageTitle) ?></span>
              </li>
            <?php endforeach; ?>
          </ol>

          <p class="portal-app__copy"><strong><?= e($title) ?>.</strong> <?= e($copy) ?></p>

          <?php /* The one question asked of somebody who came in through the
                   website or a code: build it, or take the money back. Somebody
                   whose partner already collected the whole price never sees
                   this — there is nothing left to decide. */ ?>
          <?php if ($status === 'confirm_pending'): ?>
            <div class="portal-choice">
              <p class="portal-choice__title">Shall we go ahead?</p>
              <p class="portal-choice__note">
                Continue and the delivery payment opens, and we build and deliver your unit.
                Cancel and we refund everything you have paid, in full.
              </p>

              <div class="portal-choice__actions">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                  <input type="hidden" name="choice" value="continue">
                  <button type="submit" class="btn-pill btn-pill--accent">
                    Continue with delivery <i class="bi bi-arrow-right" aria-hidden="true"></i>
                  </button>
                </form>

                <form method="post"
                      data-confirm="Cancel this order? We refund everything you have paid.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                  <input type="hidden" name="choice" value="cancel">
                  <button type="submit" class="btn-pill portal-choice__cancel">
                    Cancel and refund me
                  </button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php $totals = payment_totals($app); ?>

        <?php /* Nothing is owed until the office has approved it, so the QR
                 code and the upload box stay out of the way until then. */ ?>
        <?php if ($status !== 'rejected' && $status !== 'submitted'): ?>
          <div class="portal-plan">
            <p class="portal-plan__lead">
              <strong><?= e(money((float) $totals['paid'])) ?></strong> paid of
              <?= e(money((float) $totals['due'])) ?> — two transfers, each verified by our team.
              Quote booking number <strong><?= e($app['reference_code']) ?></strong> on every payment.
            </p>

            <?php foreach (PAYMENT_STAGES as $stageKey): ?>
              <?php
                $stage   = $totals['stages'][$stageKey];
                $isOpen  = $stage['state'] === 'due';
                $stageId = $stageKey . '-' . (int) $app['id'];
              ?>
              <?php
                /* The delivery payment is behind the finance check: the section
                   stays visible so nobody wonders where it went, but what you
                   would act on — the QR code, the form, the button — is veiled
                   until the documents are verified. */
                $sealed = $stageKey === 'delivery'
                    && empty($app['docs_verified_at'])
                    && $totals['stages']['booking']['settled'];
              ?>
              <section class="portal-stage portal-stage--<?= e($stage['state']) ?><?= $sealed ? ' is-sealed' : '' ?>">

                <header class="portal-stage__head">
                  <div>
                    <h3 class="portal-stage__title"><?= e($stage['label']) ?></h3>
                    <p class="portal-stage__amount"><?= e(money((float) $stage['amount'])) ?></p>
                  </div>
                  <span class="portal-stage__state">
                    <?php if ($stage['state'] === 'paid'): ?>
                      <i class="bi bi-check-lg" aria-hidden="true"></i> Verified
                    <?php elseif ($stage['state'] === 'checking'): ?>
                      <i class="bi bi-hourglass-split" aria-hidden="true"></i> Checking
                    <?php elseif ($stage['state'] === 'locked'): ?>
                      <i class="bi bi-lock" aria-hidden="true"></i> Not yet due
                    <?php else: ?>
                      <i class="bi bi-exclamation-circle" aria-hidden="true"></i> Due now
                    <?php endif; ?>
                  </span>
                </header>

                <p class="portal-stage__note">
                  <?php if ($stage['state'] === 'paid'): ?>
                    Verified by our team<?= $stageKey === 'booking'
                        ? ' — your unit is reserved and this amount comes off the price.'
                        : ' — nothing further is owed to us.' ?>
                  <?php elseif ($stage['state'] === 'checking'): ?>
                    Your receipt is with our team. We check every payment by hand, usually within
                    two working days.
                  <?php elseif ($sealed): ?>
                    Your booking is verified. This opens as soon as our finance team has checked your
                    documents - we email you the moment they do.
                  <?php elseif ($stage['state'] === 'locked'): ?>
                    This opens once your booking payment has been verified. We will email you when it does.
                  <?php elseif ($stageKey === 'booking'): ?>
                    Pay this to reserve your unit. It comes off the price, it is not a charge on top.
                  <?php else: ?>
                    Due now that your booking is confirmed. Pay it and upload the receipt to complete
                    the purchase.
                  <?php endif; ?>
                </p>

                <?php if (!empty($stage['reject_reason']) && $stage['state'] === 'due'): ?>
                  <p class="portal-alert portal-alert--error portal-stage__reject">
                    Your last receipt was not accepted: <?= e((string) $stage['reject_reason']) ?>
                  </p>
                <?php endif; ?>

                <?php if ($stage['payments']): ?>
                  <ul class="portal-ledger__list">
                    <?php foreach ($stage['payments'] as $entry): ?>
                      <li class="portal-entry portal-entry--<?= e($entry['status']) ?>">
                        <span class="portal-entry__amount"><?= e(money((float) $entry['amount'])) ?></span>
                        <span class="portal-entry__meta">
                          <?= e(format_datetime($entry['uploaded_at'])) ?>
                          <?php if (!empty($entry['receipt_no'])): ?>
                            · receipt <?= e($entry['receipt_no']) ?>
                          <?php endif; ?>
                          <?php if ($entry['status'] === 'rejected' && !empty($entry['reject_reason'])): ?>
                            · <?= e($entry['reject_reason']) ?>
                          <?php endif; ?>
                        </span>
                        <?php if ($entry['status'] === 'verified'): ?>
                          <a class="portal-entry__receipt" target="_blank" rel="noopener"
                             href="receipt.php?payment=<?= (int) $entry['id'] ?>">
                            <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Receipt PDF
                          </a>
                        <?php endif; ?>

                        <span class="portal-entry__state">
                          <?php if ($entry['status'] === 'verified'): ?>
                            <i class="bi bi-check-lg" aria-hidden="true"></i> Verified
                          <?php elseif ($entry['status'] === 'pending'): ?>
                            <i class="bi bi-hourglass-split" aria-hidden="true"></i> Checking
                          <?php else: ?>
                            <i class="bi bi-x-lg" aria-hidden="true"></i> Not accepted
                          <?php endif; ?>
                        </span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>

                <?php /* Sealed, it is still drawn: seeing what is coming, veiled,
                         reads better than a section with nothing in it. The
                         fieldset makes sure nothing in there can be used. */ ?>
                <?php if ($isOpen || $sealed): ?>
                  <?php /* aria-hidden alone would hide it from a screen reader while leaving
         its file field and button on the tab order — focus would land on
         something nothing announces. `inert` takes both away, which is
         what the blur already says to everyone who can see it. */ ?>
                  <div class="portal-pay"<?= $sealed ? ' inert aria-hidden="true"' : '' ?>>
                    <div class="portal-pay__qr">
                      <?php $qr = qr_path(); ?>
                      <?php if ($qr !== ''): ?>
                        <img src="../<?= e($qr) ?>"
                             alt="Payment QR code for <?= e($app['reference_code']) ?>">
                      <?php endif; ?>
                      <p class="portal-pay__note">
                        Transfer exactly <strong><?= e(money((float) $stage['amount'])) ?></strong>,
                        then upload that receipt. Quote booking number
                        <strong><?= e($app['reference_code']) ?></strong> on the payment.
                      </p>
                    </div>

                    <form class="portal-pay__form form-x" method="post" enctype="multipart/form-data">
                      <fieldset<?= $sealed ? ' disabled' : '' ?> style="border:0;margin:0;padding:0;min-width:0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                      <input type="hidden" name="stage" value="<?= e($stageKey) ?>">

                      <div class="field">
                        <label for="ref-<?= e($stageId) ?>">Payment reference or UTR (optional)</label>
                        <input id="ref-<?= e($stageId) ?>" name="payment_reference" type="text"
                               placeholder="From your bank or UPI app">
                      </div>

                      <?php /* an upload that failed says so here, against the box
                               it was chosen in, rather than at the top of a page
                               that may carry two of them */ ?>
                      <?php $stageFailed = $uploadError !== '' && $uploadStage === $stageKey; ?>

                      <div class="field<?= $stageFailed ? ' field--error' : '' ?>">
                        <label for="proof-<?= e($stageId) ?>">Proof of this payment
                          <span class="req" aria-hidden="true">*</span></label>
                        <input id="proof-<?= e($stageId) ?>" name="payment_proof" type="file"
                               accept="image/*,application/pdf" required
                               <?= $stageFailed ? 'aria-describedby="proof-error-' . e($stageId) . '" aria-invalid="true"' : '' ?>>

                        <?php if ($stageFailed): ?>
                          <p class="field-error" id="proof-error-<?= e($stageId) ?>" role="alert">
                            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                            <?= e($uploadError) ?>
                          </p>
                        <?php endif; ?>

                        <span class="field-hint">JPG, PNG, WebP or PDF, up to 10 MB</span>
                      </div>

                      <button type="submit" class="btn-pill btn-pill--accent form-x__submit">
                        Upload <?= e(strtolower($stage['label'])) ?> proof <i class="bi bi-upload"></i>
                      </button>
                    </fieldset>
                    </form>
                  </div>
                <?php endif; ?>

              </section>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (booking_paid($app) && $status !== 'rejected' && !empty($app['referral_code'])): ?>
          <?php
            $code      = (string) $app['referral_code'];
            $rewards   = referral_stats((int) $app['id']);
            $earning   = money(referral_reward());
            $referrals = referrals_for((int) $app['id']);

            /* the header's "Refer" link jumps to #referral, so only the first
               card on the page may claim that id */
            $anchor = empty($referralAnchorUsed) ? ' id="referral"' : '';
            $referralAnchorUsed = true;
          ?>
          <div class="portal-referral"<?= $anchor ?>>
            <div class="portal-referral__head">
              <div>
                <p class="portal-referral__label">Your referral code</p>
                <p class="portal-referral__code"><?= e($code) ?></p>
              </div>
              <button type="button" class="portal-referral__copy" data-copy="<?= e($code) ?>">
                <i class="bi bi-clipboard" aria-hidden="true"></i> Copy code
              </button>
            </div>

            <p class="portal-referral__copyline">Now that your booking payment is verified, this code earns you
              <strong><?= e($earning) ?></strong> every time somebody applies with it and pays their own fee.
              We check each one and transfer the money to you by hand. Share a link and the code fills itself in.</p>

            <div class="portal-referral__links">
              <button type="button" class="portal-referral__link"
                      data-copy="<?= e(referral_link($code, 'stove')) ?>">
                <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy stove link
              </button>
              <button type="button" class="portal-referral__link"
                      data-copy="<?= e(referral_link($code, 'tuktuk')) ?>">
                <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy TukTuk link
              </button>
            </div>

            <?php if ($rewards['total'] > 0): ?>
              <p class="portal-referral__stat">
                <strong><?= (int) $rewards['total'] ?></strong>
                <?= $rewards['total'] === 1 ? 'person has' : 'people have' ?> applied with your code
                &middot; <strong><?= e(money($rewards['paid'])) ?></strong> paid to you
                &middot; <strong><?= e(money($rewards['pending'])) ?></strong> pending.
              </p>

              <ul class="portal-referral__list">
                <?php foreach ($referrals as $referral): ?>
                  <li class="portal-referral__row">
                    <span class="portal-referral__who"><?= e($referral['full_name']) ?></span>
                    <span class="portal-referral__when"><?= e(format_datetime($referral['created_at'])) ?></span>
                    <span class="portal-referral__amount"><?= e(money((float) $referral['referral_reward'])) ?></span>
                    <span class="portal-referral__state portal-referral__state--<?= e($referral['referral_reward_status']) ?>">
                      <?php if ($referral['referral_reward_status'] === 'sent'): ?>
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Sent
                        <?= $referral['referral_reward_sent_at']
                              ? e(format_datetime($referral['referral_reward_sent_at']))
                              : '' ?>
                      <?php elseif ($referral['referral_reward_status'] === 'cancelled'): ?>
                        <i class="bi bi-x-lg" aria-hidden="true"></i> Cancelled
                      <?php elseif (!empty($referral['booking_paid_at'])): ?>
                        <i class="bi bi-hourglass-split" aria-hidden="true"></i> Pending payout
                      <?php else: ?>
                        <i class="bi bi-hourglass" aria-hidden="true"></i> Waiting for their booking payment
                      <?php endif; ?>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </article>
    <?php endforeach; ?>

  </div>
</section>

<?php require __DIR__ . '/partials/foot.php'; ?>
