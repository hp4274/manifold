<?php
/**
 * Status switch from a list row. POST only.
 * Fields: type, id, status, admin_note (optional), return (path back to the list).
 *
 * Two transitions send an email:
 *   → confirmed  payment details with the QR code
 *   → complete   payment verified
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/emails.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

csrf_check();

$type   = (string) ($_POST['type'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
$config = type_config($type);

if (!in_array($status, statuses_for($type), true)) {
    http_response_code(422);
    exit('Unknown status.');
}

$stmt = db()->prepare('SELECT * FROM ' . $config['table'] . ' WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Record not found.');
}

$oldStatus = (string) $row['status'];
$mailFlag  = '';

/* payment decisions belong to payment.php, which checks the receipt first */
if ($config['table'] === 'applications') {
    http_response_code(409);
    exit('Use the payment actions in the Details drawer for applications.');
}

/* the UI hides the buttons once accepted; enforce it here too */
if ($oldStatus === 'accepted' && $status !== 'accepted') {
    http_response_code(409);
    exit('This one has been accepted and can no longer be changed.');
}

if ($oldStatus !== $status) {
    db()->prepare('UPDATE ' . $config['table'] . ' SET status = ? WHERE id = ?')
        ->execute([$status, $id]);

    log_status_change($config['entity'], $id, $oldStatus, $status, (int) $user['id']);

    /* Applications are driven by payment.php, which sends the emails that go
       with a payment decision. Nothing to send from here. */
}

if (array_key_exists('admin_note', $_POST)) {
    $note = trim((string) $_POST['admin_note']);

    db()->prepare('UPDATE ' . $config['table'] . ' SET admin_note = ? WHERE id = ?')
        ->execute([$note !== '' ? $note : null, $id]);
}

/* back to whichever list the change came from */
$return = (string) ($_POST['return'] ?? '');

if (!preg_match('/^(index|list)\.php(\?[a-z0-9=&_%-]*)?$/i', $return)) {
    $return = 'list.php?type=' . urlencode($type);
}

$return .= (strpos($return, '?') === false ? '?' : '&') . 'saved=' . $id . $mailFlag . '#row-' . $id;

header('Location: ' . $return);
exit;
