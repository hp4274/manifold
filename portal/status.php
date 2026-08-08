<?php
/**
 * Applicant dashboard: the stage timeline per application, and the receipt
 * upload once an application has been confirmed.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$email = require_applicant();
$error = '';
$done  = false;

/* ---------- receipt upload ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $id = (int) ($_POST['id'] ?? 0);

    $stmt = db()->prepare('SELECT * FROM applications WHERE id = ? AND email = ?');
    $stmt->execute([$id, $email]);
    $app = $stmt->fetch();

    if (!$app) {
        $error = 'That application could not be found.';
    } elseif ($app['status'] !== 'confirmed') {
        $error = 'A receipt can only be uploaded once the application is confirmed.';
    } elseif (empty($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Choose the receipt file to upload.';
    } else {
        $file  = $_FILES['payment_proof'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);

        if ($file['size'] > UPLOAD_MAX_BYTES) {
            $error = 'That file is larger than 10 MB.';
        } elseif (!isset(UPLOAD_ALLOWED_MIME[$mime])) {
            $error = 'Upload a JPG, PNG, WebP or PDF.';
        } else {
            if (!is_dir(PAYMENT_PROOF_DIR)) {
                mkdir(PAYMENT_PROOF_DIR, 0775, true);
            }

            $name = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . UPLOAD_ALLOWED_MIME[$mime];

            if (!move_uploaded_file($file['tmp_name'], PAYMENT_PROOF_DIR . '/' . $name)) {
                $error = 'The upload did not complete. Try again.';
            } else {
                db()->prepare(
                    'UPDATE applications
                        SET payment_proof_path = ?, payment_reference = ?, payment_uploaded_at = NOW(),
                            status = ?
                      WHERE id = ?'
                )->execute([
                    $name,
                    mb_substr(trim((string) ($_POST['payment_reference'] ?? '')), 0, 120) ?: null,
                    'payment_pending',
                    $id,
                ]);

                log_status_change('application', $id, (string) $app['status'], 'payment_pending', null);

                /* tell the team there is a receipt waiting */
                $app['status']            = 'payment_pending';
                $app['payment_reference'] = $_POST['payment_reference'] ?? null;
                send_payment_received_admin($app);

                header('Location: status.php?uploaded=' . $id . '#app-' . $id);
                exit;
            }
        }
    }
}

$applications = applications_for($email);
$uploadedId   = (int) ($_GET['uploaded'] ?? 0);
$pageTitle    = 'My applications';

require __DIR__ . '/partials/head.php';
?>

<section class="section portal">
  <div class="container-x">

    <p class="eyebrow eyebrow--rule">Signed in as <?= e($email) ?></p>
    <div class="section-head">
      <h1 class="section-title">My applications.</h1>
      <p class="section-sub">Everything you have applied for, and what happens next at each stage.</p>
    </div>

    <?php if ($uploadedId > 0): ?>
      <p class="portal-alert portal-alert--ok">
        Receipt received for application #<?= $uploadedId ?>. We verify payments within two working days.
      </p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <p class="portal-alert portal-alert--error"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (!$applications): ?>
      <div class="portal-card">
        <h2>Nothing here yet</h2>
        <p class="u-sub">No application is linked to <?= e($email) ?>. If you applied with a different address,
          <a href="index.php">sign in with that one</a> instead.</p>
        <p class="u-sub"><a class="link-arrow" href="../apply-stove.html">Apply for a stove</a> ·
          <a class="link-arrow" href="../apply-tuktuk.html">Apply for a TukTuk kit</a></p>
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
            <p class="portal-app__ref"><?= e($app['reference_code']) ?></p>
            <h2><?= e(product_name((string) $app['product'])) ?></h2>
            <p class="portal-app__meta">Applied <?= e(format_datetime($app['created_at'])) ?></p>
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
        <?php endif; ?>

        <?php if ($status === 'confirmed'): ?>
          <div class="portal-pay">
            <div class="portal-pay__qr">
              <?php $qr = qr_path(); ?>
              <?php if ($qr !== ''): ?>
                <img src="../<?= e($qr) ?>" alt="Payment QR code for <?= e($app['reference_code']) ?>">
                <p class="portal-pay__note">
                  Scan with any UPI app to pay, then upload the receipt below.
                  Quote <strong><?= e($app['reference_code']) ?></strong> on the payment.
                </p>
              <?php else: ?>
                <p class="portal-pay__note">
                  The payment QR code will be emailed to you. Quote
                  <strong><?= e($app['reference_code']) ?></strong> on the payment.
                </p>
              <?php endif; ?>
            </div>

            <form class="portal-pay__form form-x" method="post" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $app['id'] ?>">

              <div class="field">
                <label for="ref-<?= (int) $app['id'] ?>">Payment reference or UTR (optional)</label>
                <input id="ref-<?= (int) $app['id'] ?>" name="payment_reference" type="text" placeholder="From your bank or UPI app">
              </div>

              <div class="field">
                <label for="proof-<?= (int) $app['id'] ?>">Payment receipt <span class="req" aria-hidden="true">*</span></label>
                <input id="proof-<?= (int) $app['id'] ?>" name="payment_proof" type="file"
                       accept="image/*,application/pdf" required>
                <span class="field-hint">JPG, PNG, WebP or PDF, up to 10 MB</span>
              </div>

              <button type="submit" class="btn-pill btn-pill--accent form-x__submit">
                Upload receipt <i class="bi bi-upload"></i>
              </button>
            </form>
          </div>
        <?php endif; ?>

        <?php if (!empty($app['payment_uploaded_at'])): ?>
          <p class="portal-app__meta">
            Receipt uploaded <?= e(format_datetime($app['payment_uploaded_at'])) ?>
            <?php if (!empty($app['payment_reference'])): ?>
              · reference <?= e($app['payment_reference']) ?>
            <?php endif; ?>
          </p>
        <?php endif; ?>

      </article>
    <?php endforeach; ?>

    <p class="portal-signout">
      <a class="link-arrow" href="logout.php">Sign out</a>
    </p>

  </div>
</section>

<?php require __DIR__ . '/partials/foot.php'; ?>
