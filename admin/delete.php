<?php
/**
 * Deletes one submission for good. POST only, CSRF-checked.
 * Any uploaded documents attached to the record are removed with it.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

csrf_check();

$type   = (string) ($_POST['type'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);
$config = type_config($type);

$stmt = db()->prepare('SELECT * FROM ' . $config['table'] . ' WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('Record not found.');
}

/* drop the uploaded files too, so nothing is orphaned in admin/uploads */
foreach (['id_document_path', 'residence_proof_path'] as $column) {
    if (empty($row[$column])) {
        continue;
    }

    $path = UPLOAD_DIR . '/' . basename((string) $row[$column]);

    if (is_file($path)) {
        unlink($path);
    }
}

db()->prepare('DELETE FROM ' . $config['table'] . ' WHERE id = ?')->execute([$id]);
db()->prepare('DELETE FROM status_log WHERE entity = ? AND entity_id = ?')
    ->execute([$config['entity'], $id]);

$return = (string) ($_POST['return'] ?? '');

if (!preg_match('/^(index|list)\.php(\?[a-z0-9=&_%-]*)?$/i', $return)) {
    $return = 'list.php?type=' . urlencode($type);
}

admin_flash(['deleted' => $id]);

header('Location: ' . $return);
exit;
