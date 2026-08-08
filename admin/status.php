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

/* the UI hides the buttons on a completed application; enforce it here too */
if ($config['table'] === 'applications' && $oldStatus === 'complete' && $status !== 'complete') {
    http_response_code(409);
    exit('This application is complete and can no longer be changed.');
}

if ($oldStatus !== $status) {
    db()->prepare('UPDATE ' . $config['table'] . ' SET status = ? WHERE id = ?')
        ->execute([$status, $id]);

    log_status_change($config['entity'], $id, $oldStatus, $status, (int) $user['id']);

    if ($config['table'] === 'applications') {
        if ($status === 'confirmed') {
            db()->prepare('UPDATE applications SET confirmed_at = NOW() WHERE id = ?')->execute([$id]);
            $row['status'] = $status;
            $mailFlag = send_payment_email($row) ? '&mail=sent' : '&mail=failed';
        }

        if ($status === 'complete') {
            db()->prepare('UPDATE applications SET completed_at = NOW(), payment_verified_at = NOW() WHERE id = ?')
                ->execute([$id]);
            $row['status'] = $status;
            $mailFlag = send_complete_email($row) ? '&mail=sent' : '&mail=failed';
        }
    }
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
