<?php
/**
 * One record's Details markup, fetched when somebody actually opens it.
 *
 * Every list used to carry the full drawer for every row — on the dashboard
 * that was 204 KB of the 241 KB sent, for ten rows nobody had clicked. The
 * lists now send the rows alone and ask here for the one that was opened.
 *
 * Returns a fragment, not a page: the drawer drops it straight into itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user = require_login();

$kind = (string) ($_GET['kind'] ?? 'application');
$id   = (int) ($_GET['id'] ?? 0);

if ($id < 1) {
    http_response_code(400);
    exit('No record asked for.');
}

/* the browser may keep this for as long as the session: a record that changes
   is a page reload away, and the drawer is read-only */
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, max-age=30');

if ($kind === 'dealer') {
    $dealer = dealer_by_id($id);

    if (!$dealer) {
        http_response_code(404);
        exit('That dealer no longer exists.');
    }

    $dealer['totals'] = dealer_totals($id);
    $srcDealer        = $dealer;

    require __DIR__ . '/partials/dealer-source.php';
    exit;
}

if ($kind === 'distributor') {
    $dist = distributor_by_id($id);

    if (!$dist) {
        http_response_code(404);
        exit('That distributor no longer exists.');
    }

    $dist['totals']  = distributor_totals($id);
    $dist['dealers'] = distributor_dealers($id);
    $srcDist         = $dist;
    $dealerLimit     = dealer_limit();

    require __DIR__ . '/partials/distributor-source.php';
    exit;
}

/* an application, an enquiry or a signup — all three share one partial */
/* type_config() answers 404 and exits on anything it does not know, which is
   the check — there is nothing to add to it here */
$type   = (string) ($_GET['type'] ?? '');
$config = type_config($type);

$stmt = db()->prepare('SELECT * FROM ' . $config['table'] . ' WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    exit('That record no longer exists.');
}

$return = (string) ($_GET['return'] ?? './');

if (!preg_match('#^(\./|list)(\?[a-z0-9=&_%-]*)?$#i', $return)) {
    $return = './';
}

$srcType   = $type;
$srcRow    = $row;
$srcReturn = $return;

require __DIR__ . '/partials/drawer-source.php';
