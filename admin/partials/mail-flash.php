<?php
/** Reports whether the email that goes with a status change actually went out. */

declare(strict_types=1);

/* set by the page that included this, out of the session flash — never out of
   the query string, which would leave the message sitting in the address bar */
$mail = $mailFlash ?? '';
$pay  = $payFlash ?? '';
?>
<?php if ($mail === 'sent'): ?>
  <p class="alert alert--ok">Email sent to the applicant.</p>
<?php elseif ($mail === 'failed'): ?>
  <p class="alert alert--error">
    Status saved, but the email could not be sent.
    <?php if (!mail_configured()): ?>
      SMTP is not configured yet — fill in <code>SMTP_HOST</code>, <code>SMTP_USER</code> and
      <code>SMTP_PASS</code> in <code>admin/config.php</code>.
    <?php else: ?>
      Check the <code>email_log</code> table for the reason.
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if ($pay === 'receipt'): ?>
  <p class="alert alert--ok">Payment accepted. The receipt has been emailed to the applicant.</p>
<?php elseif ($pay === 'rejected'): ?>
  <p class="alert alert--error">Payment rejected. The applicant has been emailed and is back to payment pending.</p>
<?php elseif ($pay === 'docs'): ?>
  <p class="alert alert--ok">Documents verified. The applicant has been emailed.</p>
<?php elseif ($pay === 'reminded'): ?>
  <p class="alert alert--ok">Reminder sent.</p>
<?php elseif ($pay === 'mailfail'): ?>
  <p class="alert alert--error">Saved, but the email did not go out. Check the <code>email_log</code> table.</p>
<?php endif; ?>
