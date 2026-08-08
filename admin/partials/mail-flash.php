<?php
/** Reports whether the email that goes with a status change actually went out. */

declare(strict_types=1);

$mail = (string) ($_GET['mail'] ?? '');
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
