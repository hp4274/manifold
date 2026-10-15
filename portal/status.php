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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'referral_claim') {
    csrf_check();

    /* A client asking to be paid the referral reward they have earned. This
       records nothing about the money — that stays the office's to send and
       mark on the Referrals page — it only emails the office the request and
       rests the button for a while so the same ask cannot arrive ten times. */
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND email = ?');
    $stmt->execute([$id, $email]);
    $referrer = $stmt->fetch();

    $stats = $referrer ? referral_stats((int) $referrer['id']) : ['payable' => 0.0];

    if (!$referrer || empty($referrer['referral_code'])) {
        $error = 'That referral could not be found.';
    } elseif (!booking_paid($referrer) || $referrer['status'] === 'rejected') {
        /* the same gate the card itself is drawn behind: a code only earns once
           the referrer's own booking is verified and their order still stands */
        $error = 'Your referral code starts earning once your own booking payment is verified.';
    } elseif ($stats['payable'] <= 0) {
        $error = 'You have no referral reward waiting to be paid just now.';
    } elseif (!referral_claim_ready($referrer)) {
        $error = 'You have already asked — the office is on it. You can ask again '
            . referral_claim_wait_label($referrer) . '.';
    } else {
        /* The cooldown is enforced in the WHERE clause, not just the PHP check
           above, so two clicks that race past the check cannot both go through:
           only the first UPDATE touches a row, and only that one emails. */
        $stmt = db()->prepare(
            'UPDATE applications
                SET referral_payout_requested_at = NOW()
              WHERE id = ? AND email = ?
                AND (referral_payout_requested_at IS NULL
                     OR referral_payout_requested_at <= (NOW() - INTERVAL ? HOUR))'
        );
        $stmt->execute([$id, $email, REFERRAL_CLAIM_COOLDOWN_HOURS]);

        /* Only the click that actually moved the row says so. A second one that
           raced past the check above changed nothing and must not be answered
           with "we have told the office". */
        if ($stmt->rowCount() === 1) {
            $amount = (float) $stats['payable'];
            $count  = (int) ($stats['payable_count'] ?? 0);

            after_response(static function () use ($referrer, $amount, $count): void {
                send_referral_claim_admin($referrer, $amount, $count);
            });

            header('Location: status?claimed=1#referral');
            exit;
        }

        header('Location: status#referral');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'documents') {
    csrf_check();

    /* ---------- answering a refusal of the paperwork ----------
       Finance turned the documents down and said why. This is the applicant's
       reply: the corrected files, and the ID number if that was what was wrong.
       It changes nothing about the money and moves no status — the application
       is still `docs_pending` — it only puts something new in front of finance
       and stamps when it arrived, which is what clears the refusal. */
    $id = (int) ($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND email = ?');
    $stmt->execute([$id, $email]);
    $app = $stmt->fetch();

    $chose = static function (string $field): bool {
        return !empty($_FILES[$field]) && (int) $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE;
    };

    if (!$app) {
        $error = 'That application could not be found.';
    } elseif (!docs_refused($app)) {
        /* already answered, already verified, or never refused */
        $error = 'There is nothing to correct on that application just now.';
    } elseif (!$chose('aadhaar_doc_file') && !$chose('electricity_bill_file')) {
        $uploadError = 'Choose at least one corrected document to send.';
        $uploadStage = 'documents';
    } else {
        $saved  = [];
        $failed = [];

        foreach (['aadhaar_doc_file' => 'aadhaar_doc_path',
                  'electricity_bill_file' => 'electricity_bill_path'] as $field => $column) {
            if (!$chose($field)) {
                continue;
            }

            /* store_upload() drops anything of the wrong type or over the limit
               and says nothing, which is right for an application form nobody
               is watching — here somebody is waiting on an answer, so a file
               that did not land has to be said out loud rather than silently
               leaving the old one in place */
            $name = store_upload($field);

            if ($name === null) {
                $failed[] = $field === 'aadhaar_doc_file' ? 'Aadhaar card' : 'electricity bill';
                continue;
            }

            $saved[$column] = $name;
        }

        if ($failed) {
            $uploadError = 'We could not take your ' . implode(' or the ', $failed)
                . '. Send a JPG, PNG, WebP or PDF under '
                . (int) (UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB.';
            $uploadStage = 'documents';
        } else {
            $idNumber = preg_replace('/\D+/', '', (string) ($_POST['aadhaar_number'] ?? ''));

            $set    = ['docs_resent_at = NOW()'];
            $params = [];

            foreach ($saved as $column => $name) {
                $set[]    = $column . ' = ?';
                $params[] = $name;
            }

            if (strlen($idNumber) === 12 && $idNumber !== (string) ($app['aadhaar_number'] ?? '')) {
                $set[]    = 'aadhaar_number = ?';
                $params[] = $idNumber;
            }

            $params[] = $id;
            $params[] = $email;

            db()->prepare('UPDATE applications SET ' . implode(', ', $set)
                . ' WHERE id = ? AND email = ?')->execute($params);

            $reason = (string) ($app['docs_reject_reason'] ?? '');
            $fresh  = db()->prepare('SELECT * FROM applications WHERE id = ?');
            $fresh->execute([$id]);
            $after = $fresh->fetch() ?: $app;

            /* the office hears about it after the applicant has their page back:
               nothing on their side is waiting on the letter */
            after_response(static function () use ($after, $reason): void {
                send_documents_resent_admin($after, $reason);
            });

            header('Location: status?documents=' . $id . '#app-' . $id);
            exit;
        }
    }
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

    if ($app && $choice === 'cancel') {
        if (!cancel_window_open($app)) {
            $error = 'You can no longer cancel. It is open for ' . payment_deadline_hours()
                . ' hours after your booking payment is verified, and closes for good once your documents are verified.';
        } else {
            db()->prepare(
                'UPDATE applications SET delivery_choice = ?, delivery_choice_at = NOW()
                  WHERE id = ? AND email = ? AND docs_verified_at IS NULL AND kyc_verified_at IS NULL'
            )->execute(['cancel', $id, $email]);

            sync_application_status($id);

            $fresh = db()->prepare('SELECT * FROM applications WHERE id = ?');
            $fresh->execute([$id]);
            $after = $fresh->fetch() ?: $app;

            after_response(static function () use ($after): void {
                send_order_cancelled_email($after);
                send_delivery_choice_admin($after, 'cancel');
            });

            header('Location: status?choice=cancel#app-' . $id);
            exit;
        }
    } elseif (!$app) {
        $error = 'That application could not be found.';
    } elseif ($due === null || (is_full_payment($app) && $stage !== 'full')) {
        $error = 'That payment could not be matched to your application.';
    } elseif ($app['status'] === 'rejected' || $app['status'] === 'expired' || is_application_expired($app)) {
        $error = 'This application is no longer active or the payment window has expired.';
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

    <?php /* The heading is not drawn: the address above and the booking number
             on every card already say whose page this is, and the sentence
             under it said nothing the cards do not. It stays in the markup for
             a screen reader, which has no cards to look at. */ ?>
    <h1 class="visually-hidden"><?= $who !== '' ? e($who) . '.' : 'My applications.' ?></h1>

    <?php if ($uploadedId > 0): ?>
      <p class="portal-alert portal-alert--ok">
        <?= e(payment_stage_label($uploadedStage)) ?> receipt received for application #<?= $uploadedId ?>.
        We verify payments within two working days.
      </p>
    <?php endif; ?>

    <?php if (isset($_GET['pg_paid'])): ?>
      <p class="portal-alert portal-alert--ok">
        Payment successful! Your <?= e(payment_stage_label($uploadedStage)) ?> has been verified
        automatically. A receipt is on its way to your email.
      </p>
    <?php endif; ?>

    <?php if (isset($_GET['pg_pending'])): ?>
      <p class="portal-alert portal-alert--warn">
        We are still confirming this payment with the bank. If money left your account it is
        safe — the receipt will follow by email, usually within a few minutes. Please do not
        pay again; call +91 97251 54186 if you do not hear from us.
      </p>
    <?php endif; ?>

    <?php if (isset($_GET['pg_failed'])): ?>
      <p class="portal-alert portal-alert--error">
        The payment did not go through. No money has been charged. You can try again, or pay
        via bank transfer and upload the receipt instead.
      </p>
    <?php endif; ?>

    <?php if (($_GET['pg_error'] ?? '') === 'expired'): ?>
      <p class="portal-alert portal-alert--error">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        This application's 48-hour payment deadline has expired. Payments can no longer be processed.
      </p>
    <?php elseif (($_GET['pg_error'] ?? '') === 'partner'): ?>
      <p class="portal-alert portal-alert--error">
        <i class="bi bi-person-badge" aria-hidden="true"></i>
        This order is paid to the dealer or distributor who sold it, not online. Their payment
        details are on the order below — pay them and upload the proof.
      </p>
    <?php elseif (($_GET['pg_error'] ?? '') === 'low_stock'): ?>
      <p class="portal-alert portal-alert--error">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        This booking is held for a stock check and is waiting on approval from the office.
        Nothing is owed until that is done — we will write to you as soon as it clears.
      </p>
    <?php elseif (isset($_GET['pg_error'])): ?>
      <p class="portal-alert portal-alert--error">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        We could not connect to the payment gateway just now. Please try again in a few minutes,
        or pay via bank transfer and upload the receipt.
      </p>
    <?php endif; ?>

    <?php if ((int) ($_GET['documents'] ?? 0) > 0): ?>
      <p class="portal-alert portal-alert--ok">
        Your corrected documents are with our finance team. We check them by hand, usually within two
        working days, and email you the moment they pass.
      </p>
    <?php endif; ?>

    <?php if (isset($_GET['claimed'])): ?>
      <p class="portal-alert portal-alert--ok">
        Thanks — we have told the office you are waiting on your referral reward. They pay these by hand,
        so allow a couple of working days.
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

    <?php /* the orders on the left, the buy-again card parked on the right where
             it stays put while a long list of stages scrolls past it */ ?>
    <div class="portal-layout<?= $applications ? '' : ' portal-layout--single' ?>">
      <div class="portal-layout__main">

    <?php foreach ($applications as $app): ?>
      <?php
        $status    = (string) $app['status'];
        $plan      = (string) ($app['payment_plan'] ?? '');
        $appStages = application_stages($app);
        $stageIx   = array_search($status, $appStages, true);
        [$title, $copy] = stage_copy($status, $plan);

        /* a sale made with a partner's code is paid to that partner, who also
           checks the proof - for the booking and the delivery payment alike */
        $payee   = application_payee($app);
        $checker = $payee ? 'your ' . strtolower($payee['kind']) : 'our team';

        if ($payee && in_array($status, ['booking_review', 'delivery_review'], true)) {
            $copy = 'Your ' . strtolower($payee['kind']) . ' is checking your proof of payment and will confirm it '
                . 'here. Nothing more is needed for now.';
        }

        $totals    = payment_totals($app);
        $canCancel = cancel_window_open($app);
        /* "locked" is news only once there was a booking to cancel */
        $cancellationLocked = !$canCancel && booking_paid($app);

        /* Same stage, opposite news: the finance team has looked and cannot
           accept the paperwork. The step keeps its title — this is still where
           the application is — but "we are checking your documents" is no
           longer true and must not be the first thing they read. The reason
           itself is beside the delivery payment, which is what it holds shut. */
        if (docs_refused($app)) {
            $copy = 'Our finance team could not accept the documents you gave with the application. '
                . 'Send the corrected ones below and we will check again. Your application stands and '
                . 'your booking payment is safe.';
        } elseif (docs_resent($app)) {
            $copy = 'We have the corrected documents you sent and our finance team is checking them. '
                . 'The delivery payment opens as soon as they pass, and we email you when it does.';
        }
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
          <?php if ($canCancel): ?>
            <form class="portal-app__undo" method="post"
                  data-confirm="Cancel this booking? We refund everything you have paid.">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
              <input type="hidden" name="choice" value="cancel">
              <button type="submit" class="btn-pill portal-choice__cancel">
                <i class="bi bi-x-circle" aria-hidden="true"></i> Cancel booking
              </button>
              <span class="portal-app__undo-note">Changed your mind? Everything you have paid comes back.</span>
            </form>
          <?php elseif ($cancellationLocked): ?>
            <span class="portal-lock-note">
              <i class="bi bi-shield-lock-fill" aria-hidden="true"></i> Cancellation locked
            </span>
          <?php endif; ?>
        </header>

        <?php if ($canCancel): ?>
          <div class="portal-deadline-banner portal-deadline-banner--active" data-deadline="<?= e(date('c', strtotime($app['payment_deadline_at']))) ?>">
            <div class="portal-deadline-icon"><i class="bi bi-stopwatch-fill"></i></div>
            <div class="portal-deadline-text">
              <strong>You can still cancel</strong>
              <p>You can cancel this booking and get everything back until
                <?= e(format_datetime($app['payment_deadline_at'])) ?>, or until our finance team verifies
                your documents if that comes first. After that the booking is final.</p>
            </div>
            <div class="portal-deadline-clock">
              <span class="portal-timer-badge"><i class="bi bi-hourglass-split"></i> <span class="timer-countdown">Calculating...</span></span>
            </div>
          </div>
        <?php endif; ?>

        <?php /* An order that ended - turned down, or cancelled by the client
                 and being refunded - is not somewhere on the timeline, so it
                 says what happened instead of drawing eight empty steps. */ ?>
        <?php if (in_array($status, ['rejected', 'cancelled', 'refunded', 'expired'], true)): ?>
          <p class="portal-alert portal-alert--<?= in_array($status, ['rejected', 'expired'], true) ? 'error' : 'warn' ?>">
            <strong><?= e($title) ?>.</strong> <?= e($copy) ?>
          </p>
        <?php else: ?>
          <ol class="portal-steps">
            <?php foreach ($appStages as $i => $stage): ?>
              <?php
                $state = $stageIx === false ? 'todo' : ($i < $stageIx ? 'done' : ($i === $stageIx ? 'now' : 'todo'));

                /* the final stage is an end point, not a step in progress —
                   once it is reached it gets the tick like the others */
                if ($state === 'now' && $stage === 'complete') {
                    $state = 'done';
                }

                [$stageTitle] = stage_copy($stage, $plan);
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

        <?php endif; ?>

        <?php /* Nothing is owed until the office has approved it, so the QR
                 code and the upload box stay out of the way until then. */ ?>
        <?php if ($status !== 'rejected' && $status !== 'submitted' && $status !== 'expired'): ?>
          <div class="portal-plan">
            <p class="portal-plan__lead">
              <strong><?= e(money((float) $totals['paid'])) ?></strong> paid of
              <?= e(money((float) $totals['due'])) ?> — <?= is_full_payment($app) ? 'one transfer' : 'two transfers, each' ?>
              verified by <?= $payee ? 'your ' . e(strtolower($payee['kind'])) : 'our team' ?>.
              Quote booking number <strong><?= e($app['reference_code']) ?></strong> on every payment.
            </p>

            <?php foreach (PAYMENT_STAGES as $stageKey): ?>
              <?php
                $stage   = $totals['stages'][$stageKey];
                $isOpen  = $stage['state'] === 'due';
                $stageId = $stageKey . '-' . (int) $app['id'];

                /* a full payer pays the one full transfer and nothing else; a loan
                   customer sees it only once they have chosen it */
                if (is_full_payment($app)
                    ? $stageKey !== 'full'
                    : ($stageKey === 'full' && !$stage['settled'] && $stage['waiting'] <= 0)) {
                    continue;
                }
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
                    Verified by <?= e($checker) ?><?= $stageKey === 'booking'
                        ? ' — your unit is reserved and this amount comes off the price.'
                        : ' — nothing further is owed to us.' ?>
                  <?php elseif ($stage['state'] === 'checking'): ?>
                    <?php if ($payee): ?>
                      Your proof is with <?= e($checker) ?>, who checks it and confirms it here.
                    <?php else: ?>
                      Your receipt is with our team. We check every payment by hand, usually within
                      two working days.
                    <?php endif; ?>
                  <?php elseif ($sealed && docs_refused($app)): ?>
                    Your booking is verified. This opens once our finance team can accept your
                    documents - see below for what they need.
                  <?php elseif ($sealed): ?>
                    Your booking is verified. This opens as soon as our finance team has checked your
                    documents - we email you the moment they do.
                  <?php elseif ($stage['state'] === 'locked'): ?>
                    This opens once your booking payment has been verified. We will email you when it does.
                  <?php elseif ($stageKey === 'full'): ?>
                    The whole price in one payment. Once it is verified your order is complete.
                  <?php elseif ($stageKey === 'booking'): ?>
                    Pay this to reserve your unit. It comes off the price, it is not a charge on top.
                  <?php else: ?>
                    Your documents are verified.
                    <?= $payee ? 'Pay ' . e($checker) . ' this amount and upload the proof' : 'Pay it and upload the receipt' ?>
                    to complete the purchase.
                  <?php endif; ?>
                </p>

                <?php if (!empty($stage['reject_reason']) && $stage['state'] === 'due'): ?>
                  <p class="portal-alert portal-alert--error portal-stage__reject">
                    Your last receipt was not accepted: <?= e((string) $stage['reject_reason']) ?>
                  </p>
                <?php endif; ?>

                <?php /* The finance team's verdict on the paperwork sits on this
                         stage because this is the stage it holds shut. There is
                         no upload box for documents, so it says how to send them
                         rather than pointing at a form that is not there. */ ?>
                <?php if ($sealed && docs_refused($app)): ?>
                  <p class="portal-alert portal-alert--error portal-stage__reject">
                    Your documents were not accepted: <?= e((string) $app['docs_reject_reason']) ?>
                    <br>
                    Send the corrected ones below and we will check them again. Your application
                    stands and your booking payment is safe.
                  </p>

                  <?php /* The answer to that refusal, where the refusal is: the
                           files themselves, and the ID number in case that was
                           what did not match. Sending them puts the application
                           back in front of finance — nothing else about it
                           changes, and nothing they have paid is touched. */ ?>
                  <?php $docsFailed = $uploadError !== '' && $uploadStage === 'documents'; ?>
                  <form class="portal-docs form-x" method="post" enctype="multipart/form-data"
                        id="documents-<?= (int) $app['id'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="documents">
                    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">

                    <p class="portal-docs__title">Send corrected documents</p>
                    <p class="portal-docs__note">
                      Replace whichever one they asked about — the other is left exactly as it is.
                      We check them by hand and email you either way.
                    </p>

                    <?php if ($docsFailed): ?>
                      <p class="field-error" role="alert">
                        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                        <?= e($uploadError) ?>
                      </p>
                    <?php endif; ?>

                    <div class="field">
                      <label for="idnum-<?= (int) $app['id'] ?>">Aadhaar number</label>
                      <input id="idnum-<?= (int) $app['id'] ?>" name="aadhaar_number" type="text"
                             inputmode="numeric" maxlength="14" value="<?= e((string) ($app['aadhaar_number'] ?? '')) ?>">
                    </div>

                    <div class="field">
                      <label for="iddoc-<?= (int) $app['id'] ?>">Aadhaar card</label>
                      <input id="iddoc-<?= (int) $app['id'] ?>" name="aadhaar_doc_file" type="file"
                             accept="image/*,application/pdf">
                      <span class="field-hint">JPG, PNG, WebP or PDF, up to 10 MB</span>
                    </div>

                    <div class="field">
                      <label for="resproof-<?= (int) $app['id'] ?>">Electricity bill</label>
                      <input id="resproof-<?= (int) $app['id'] ?>" name="electricity_bill_file" type="file"
                             accept="image/*,application/pdf">
                      <span class="field-hint">JPG, PNG, WebP or PDF, up to 10 MB</span>
                    </div>

                    <button type="submit" class="btn-pill btn-pill--accent form-x__submit">
                      Send for checking <i class="bi bi-upload" aria-hidden="true"></i>
                    </button>
                  </form>
                <?php elseif ($sealed && docs_resent($app)): ?>
                  <p class="portal-alert portal-alert--ok portal-stage__reject">
                    Corrected documents sent <?= e(format_datetime((string) $app['docs_resent_at'])) ?>.
                    They are with our finance team — we email you as soon as they pass.
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
                        <?php /* The per-payment status the stage header already
                                 carries is not repeated here; the receipt row
                                 shows only the receipt link. */ ?>
                        <?php if ($entry['status'] === 'verified'): ?>
                          <a class="portal-entry__receipt" target="_blank" rel="noopener"
                             href="receipt.php?payment=<?= (int) $entry['id'] ?>">
                            <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Receipt PDF
                          </a>
                        <?php endif; ?>
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

                    <?php /* ---- Pay Now: gateway button (primary path) ----
                             Not for a sale made with a partner's code: that money
                             goes to the partner, never through the company. */ ?>
                    <?php if (!$payee): ?>
                    <div class="portal-pay__gateway">
                      <p class="portal-pay__note">
                        Pay <strong><?= e(money((float) $stage['amount'])) ?></strong> securely
                        using your card, net banking, or UPI.
                        Booking number <strong><?= e($app['reference_code']) ?></strong>.
                      </p>

                      <div class="portal-pay__modes" aria-label="Accepted payment methods">
                        <span class="portal-pay__mode"><i class="bi bi-credit-card" aria-hidden="true"></i> Cards</span>
                        <span class="portal-pay__mode"><i class="bi bi-bank" aria-hidden="true"></i> Net Banking</span>
                        <span class="portal-pay__mode"><i class="bi bi-phone" aria-hidden="true"></i> UPI</span>
                      </div>

                      <div class="portal-pay__action-row" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                        <form method="post" action="pg-initiate.php">
                          <fieldset<?= $sealed ? ' disabled' : '' ?> style="border:0;margin:0;padding:0;min-width:0">
                          <?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                          <input type="hidden" name="stage" value="<?= e($stageKey) ?>">
                          <button type="submit" class="btn-pill btn-pill--accent portal-pay__btn">
                            <i class="bi bi-shield-lock" aria-hidden="true"></i>
                            Pay <?= e($stageKey === 'booking' ? 'Booking ' . money((float) $stage['amount']) : money((float) $stage['amount'])) ?> Now
                          </button>
                          </fieldset>
                        </form>

                        <?php if ($stageKey === 'booking' && !$totals['stages']['full']['settled']): ?>
                          <form method="post" action="pg-initiate.php">
                            <fieldset<?= $sealed ? ' disabled' : '' ?> style="border:0;margin:0;padding:0;min-width:0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                            <input type="hidden" name="stage" value="full">
                            <button type="submit" class="btn-pill btn-pill--outline portal-pay__btn">
                              <i class="bi bi-check2-all" aria-hidden="true"></i>
                              Pay Full Amount (<?= e(money((float) $totals['stages']['full']['amount'])) ?>)
                            </button>
                            </fieldset>
                          </form>
                        <?php endif; ?>
                      </div>

                      <p class="portal-pay__secure">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        Secured by ICICI Bank Payment Gateway. Your payment is verified instantly.
                      </p>
                    </div>
                    <?php endif; ?>

                    <?php /* ---- Fallback: manual receipt upload ---- */ ?>
                    <?php if ($payee): ?>
                    <div class="portal-pay__fallback portal-pay__fallback--payee">
                    <?php else: ?>
                    <details class="portal-pay__fallback">
                      <summary class="portal-pay__fallback-toggle">
                        <i class="bi bi-upload" aria-hidden="true"></i>
                        Already paid via bank transfer? Upload receipt instead
                      </summary>
                    <?php endif; ?>

                      <div class="portal-pay__fallback-body<?= $payee ? ' portal-pay__fallback-body--payee' : '' ?>">
                        <?php if ($payee): ?>
                          <?php $payRows = array_filter([
                              'Phone' => $payee['mobile_number'], 'Alternate phone' => $payee['alt_mobile_number'],
                              'Email' => $payee['email'], 'UPI' => $payee['upi_id'], 'Bank' => $payee['bank_name'],
                              'Account' => $payee['bank_account'], 'IFSC' => $payee['bank_ifsc'],
                          ], static fn ($v): bool => !empty($v)); ?>
                          <div class="portal-payee">
                            <div class="portal-payee__who">
                              <span class="portal-payee__kind">Pay your <?= e(strtolower($payee['kind'])) ?> directly</span>
                              <strong class="portal-payee__name"><?= e($payee['full_name']) ?></strong>
                              <span class="portal-app__code"><?= e($payee['code']) ?></span>
                            </div>
                            <p class="portal-payee__amount">
                              Transfer <strong><?= e(money((float) $stage['amount'])) ?></strong> to
                              <?= e($payee['full_name']) ?>, not to Manifold. Quote booking number
                              <strong><?= e($app['reference_code']) ?></strong> on the payment.
                            </p>
                            <?php if ($payRows): ?>
                              <dl class="portal-payee__rows">
                                <?php foreach ($payRows as $payLabel => $payValue): ?>
                                  <div><dt><?= e($payLabel) ?></dt><dd><?= e((string) $payValue) ?></dd></div>
                                <?php endforeach; ?>
                              </dl>
                            <?php else: ?>
                              <p class="portal-payee__amount">Ask your <?= e(strtolower($payee['kind'])) ?> for their payment details.</p>
                            <?php endif; ?>
                            <ol class="portal-payee__steps">
                              <li>Pay <?= e($payee['full_name']) ?></li>
                              <li>Upload the proof below</li>
                              <li>They confirm it here</li>
                            </ol>
                          </div>
                        <?php else: ?>
                        <div class="portal-pay__qr">
                          <?php
                            $qr = qr_path();
                            $qrSize = $qr === '' ? false : @getimagesize((string) qr_file());
                          ?>
                          <?php if ($qr !== ''): ?>
                            <img src="../<?= e($qr) ?>"
                                 <?= $qrSize ? 'width="' . (int) $qrSize[0] . '" height="' . (int) $qrSize[1] . '"' : '' ?>
                                 alt="Payment QR code for <?= e($app['reference_code']) ?>">
                          <?php endif; ?>
                          <p class="portal-pay__note">
                            <?php if ($stageKey === 'booking' && !$totals['stages']['full']['settled']): ?>
                              Transfer <strong><?= e(money((float) $stage['amount'])) ?></strong> for Booking or
                              <strong><?= e(money((float) $totals['stages']['full']['amount'])) ?></strong> for Full Payment,
                              then upload your receipt below. Quote booking number
                              <strong><?= e($app['reference_code']) ?></strong> on the payment.
                            <?php else: ?>
                              Transfer exactly <strong><?= e(money((float) $stage['amount'])) ?></strong>,
                              then upload that receipt. Quote booking number
                              <strong><?= e($app['reference_code']) ?></strong> on the payment.
                            <?php endif; ?>
                          </p>
                        </div>
                        <?php endif; ?>

                        <form class="portal-pay__form form-x" method="post" enctype="multipart/form-data">
                          <fieldset<?= $sealed ? ' disabled' : '' ?> style="border:0;margin:0;padding:0;min-width:0">
                          <?= csrf_field() ?>
                          <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">

                          <?php if ($stageKey === 'booking' && !$totals['stages']['full']['settled']): ?>
                            <div class="field">
                              <label for="stage-choice-<?= e($stageId) ?>">Payment Option</label>
                              <select id="stage-choice-<?= e($stageId) ?>" name="stage" class="form-select">
                                <option value="booking">Booking Payment (<?= e(money((float) $stage['amount'])) ?>)</option>
                                <option value="full">Full Payment (<?= e(money((float) $totals['stages']['full']['amount'])) ?>)</option>
                              </select>
                            </div>
                          <?php else: ?>
                            <input type="hidden" name="stage" value="<?= e($stageKey) ?>">
                          <?php endif; ?>

                          <div class="field">
                            <label for="ref-<?= e($stageId) ?>">Payment reference or UTR (optional)</label>
                            <input id="ref-<?= e($stageId) ?>" name="payment_reference" type="text"
                                   placeholder="From your bank or UPI app">
                          </div>

                          <?php $stageFailed = $uploadError !== '' && $uploadStage === $stageKey; ?>

                          <div class="field<?= $stageFailed ? ' field--error' : '' ?>">
                            <label for="proof-<?= e($stageId) ?>"><?= $payee
                                ? 'Upload proof of payment to ' . e($payee['full_name'])
                                : 'Proof of this payment' ?>
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

                          <button type="submit" class="btn-pill form-x__submit">
                            Upload <?= e(strtolower($stage['label'])) ?> proof <i class="bi bi-upload"></i>
                          </button>
                        </fieldset>
                        </form>
                      </div>
                    <?php if ($payee): ?>
                    </div>
                    <?php else: ?>
                    </details>
                    <?php endif; ?>

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
              <?php /* both codes travel: theirs, so the reward is theirs, and the
                       partner who sold to them, so the sale stays where it was */ ?>
              <button type="button" class="portal-referral__link"
                      data-copy="<?= e(client_referral_link($app, 'stove')) ?>">
                <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy stove link
              </button>
              <button type="button" class="portal-referral__link"
                      data-copy="<?= e(client_referral_link($app, 'tuktuk')) ?>">
                <i class="bi bi-link-45deg" aria-hidden="true"></i> Copy TukTuk link
              </button>
            </div>

            <?php if ($rewards['total'] > 0): ?>
              <p class="portal-referral__stat">
                <strong><?= (int) $rewards['total'] ?></strong>
                <?= $rewards['total'] === 1 ? 'person has' : 'people have' ?> applied with your code
                &middot; <strong><?= e(money($rewards['paid'] + $rewards['pending'])) ?></strong> earned
                &middot; <strong><?= e(money($rewards['paid'])) ?></strong> paid to you
                &middot; <strong><?= e(money($rewards['pending'])) ?></strong> pending
                <?php if ($rewards['payable'] > 0): ?>
                  &middot; <strong><?= e(money($rewards['payable'])) ?></strong> ready to be sent
                <?php endif; ?>.
              </p>

              <?php /* The money side of the same list, read as a statement rather
                       than a set of rows: what has been sent, what is still owed,
                       and when the office was last asked for it. Without it a
                       referrer had to add the rows up themselves. */ ?>
              <?php if (!empty($app['referral_payout_requested_at'])): ?>
                <p class="portal-referral__stat portal-referral__stat--muted">
                  Last payout request: <?= e(format_datetime($app['referral_payout_requested_at'])) ?>.
                </p>
              <?php endif; ?>

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
            <?php else: ?>
              <p class="portal-referral__stat portal-referral__stat--muted">
                Nobody has applied with your code yet — nothing earned so far.
              </p>
            <?php endif; ?>

            <?php /* Once there is a reward sitting verified-but-unpaid, the client
                     can nudge the office rather than only wait — the same idea as
                     a dealer raising a voucher. Pressing it emails the office and
                     then rests for REFERRAL_CLAIM_COOLDOWN_HOURS so one person
                     cannot send the same claim over and over. */ ?>
            <?php if ($rewards['payable'] > 0): ?>
              <div class="portal-referral__claim">
                <?php if (referral_claim_ready($app)): ?>
                  <p class="portal-referral__claim-note">
                    <strong><?= e(money($rewards['payable'])) ?></strong> is verified and waiting to be paid
                    to you. Ask the office to send it and we will get to it by hand.
                  </p>
                  <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="referral_claim">
                    <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">
                    <button type="submit" class="btn-pill btn-pill--accent">
                      Request my <?= e(money($rewards['payable'])) ?> payout
                      <i class="bi bi-cash-coin" aria-hidden="true"></i>
                    </button>
                  </form>
                <?php else: ?>
                  <p class="portal-referral__claim-note portal-referral__claim-note--sent">
                    <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                    Payout requested — the office is on it. You can ask again
                    <?= e(referral_claim_wait_label($app)) ?>.
                  </p>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      </article>
    <?php endforeach; ?>

      </div>

    <?php if ($applications): ?>
      <aside class="portal-layout__side">
      <div class="portal-card portal-again">
        <p class="eyebrow eyebrow--rule">Buy again</p>
        <h2>Want another one?</h2>
        <p class="u-sub portal-card__lead">Your name, address and contact details carry over — only the
          product details are left to answer.</p>

        <div class="role-picker">
          <a class="role-card" href="../apply-stove">
            <span class="role-card__icon"><i class="bi bi-fire" aria-hidden="true"></i></span>
            <span class="role-card__text">
              <strong>Clean cookstove</strong>
              <span>Less smoke, less fuel, same kitchen.</span>
            </span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>

          <a class="role-card" href="../apply-tuktuk">
            <span class="role-card__icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
            <span class="role-card__text">
              <strong>TukTuk conversion kit</strong>
              <span>Turn a petrol three-wheeler electric.</span>
            </span>
            <i class="bi bi-arrow-right" aria-hidden="true"></i>
          </a>
        </div>
      </div>
      </aside>
    <?php endif; ?>

    </div>

  </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var banners = document.querySelectorAll('.portal-deadline-banner--active[data-deadline]');
  banners.forEach(function(banner) {
    var deadlineStr = banner.getAttribute('data-deadline');
    var deadline = new Date(deadlineStr).getTime();
    var timerDisplay = banner.querySelector('.timer-countdown');
    if (!deadline || !timerDisplay) return;

    function update() {
      var now = new Date().getTime();
      var diff = deadline - now;
      if (diff <= 0) {
        timerDisplay.textContent = 'Expired';
        banner.classList.remove('portal-deadline-banner--active');
        banner.classList.add('portal-deadline-banner--expired');
        var textP = banner.querySelector('.portal-deadline-text p');
        if (textP) textP.textContent = 'The 48-hour payment window has expired.';
        return;
      }
      var hours = Math.floor(diff / (1000 * 60 * 60));
      var minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
      var seconds = Math.floor((diff % (1000 * 60)) / 1000);
      timerDisplay.textContent = hours + 'h ' + minutes + 'm ' + seconds + 's remaining';
    }
    update();
    setInterval(update, 1000);
  });
});
</script>

<?php require __DIR__ . '/partials/foot.php'; ?>
