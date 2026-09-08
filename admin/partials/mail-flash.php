<?php
/**
 * The one line a page says after an action.
 *
 * What was done and whether the email that goes with it went out are two halves
 * of the same sentence, so they are one paragraph: two of them made two toasts
 * for one click. admin.js takes the first sentence as the toast's headline and
 * whatever follows as the quieter line under it.
 *
 * Set by the page that included this, out of the session flash — never out of
 * the query string, which would leave the message sitting in the address bar.
 * Expects $savedNote / $deletedNote (what happened) and $mailFlash / $payFlash
 * (what was emailed); any of them may be empty.
 */

declare(strict_types=1);

$mail    = $mailFlash ?? '';
$pay     = $payFlash ?? '';
$note    = (string) ($savedNote ?? '');
$deleted = (string) ($deletedNote ?? '');

/* the email half of the sentence, and whether it turns the line into a warning */
$tail = '';
$bad  = false;

if ($mail === 'sent') {
    $tail = 'Email sent to the applicant.';
} elseif ($mail === 'failed') {
    $bad  = true;
    $tail = 'The email could not be sent. ' . (mail_configured()
        ? 'Check the <code>email_log</code> table for the reason.'
        : 'SMTP is not configured yet — fill in <code>SMTP_HOST</code>, <code>SMTP_USER</code> and '
          . '<code>SMTP_PASS</code> in <code>admin/config.php</code>.');
}

/* a payment decision says the same thing about its own email */
$payNotes = [
    'receipt'       => ['The receipt has been emailed to the applicant.', false],
    'rejected'      => ['The applicant has been emailed and is back to payment pending.', true],
    'docs'          => ['The applicant has been emailed.', false],
    'docs_rejected' => ['The applicant has been emailed the reason and asked to send corrected ones. '
                        . 'Their application stands and the delivery payment stays shut until you verify them.', true],
    'refunded'      => ['The client has been emailed that the money is on its way back.', false],
    'reminded'      => ['Reminder sent.', false],
    'mailfail'      => ['The email did not go out. Check the <code>email_log</code> table.', true],
];

if (isset($payNotes[$pay])) {
    [$tail, $bad] = $payNotes[$pay];
}
?>
<?php if ($note !== '' || $tail !== ''): ?>
  <p class="alert alert--<?= $bad ? 'error' : 'ok' ?>"><?= $note === '' ? '' : e($note) . ' ' ?><?= $tail ?></p>
<?php endif; ?>

<?php if ($deleted !== ''): ?>
  <p class="alert alert--error"><?= e($deleted) ?></p>
<?php endif; ?>
