<?php
/**
 * Re-stamps the ?v= on the public pages' CSS and JS.
 *
 * The admin templates get this for free from asset_url(), which reads the
 * file's own modified time. The public pages are static HTML, so nothing can
 * do it at render time — and a stamp that is edited by hand is a stamp
 * somebody forgets, which is how a page ends up running new markup against a
 * cached stylesheet.
 *
 * Run it from the command line after changing anything in assets/:
 *
 *     php admin/bump-assets.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root  = dirname(__DIR__);
$pages = array_merge(
    glob($root . '/*.html') ?: [],
    [$root . '/portal/partials/head.php', $root . '/portal/partials/foot.php']
);

$touched = 0;

foreach ($pages as $page) {
    if (!is_file($page)) {
        continue;
    }

    $html = (string) file_get_contents($page);

    $stamped = preg_replace_callback(
        '#((?:\.\./)?assets/(?:css|js)/[a-z-]+\.(?:css|js))(\?v=\d+)?#',
        static function (array $m) use ($root): string {
            $file = $root . '/' . ltrim(str_replace('../', '', $m[1]), '/');

            return $m[1] . '?v=' . (is_file($file) ? filemtime($file) : time());
        },
        $html
    );

    if ($stamped !== null && $stamped !== $html) {
        file_put_contents($page, $stamped);
        $touched++;
        echo 'stamped ', basename($page), PHP_EOL;
    }
}

echo $touched, ' file', $touched === 1 ? '' : 's', ' updated', PHP_EOL;
