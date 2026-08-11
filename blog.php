<?php
/**
 * Public blog feed.
 *
 * The home page is static HTML, so it asks here for the posts to show. Only
 * live posts are ever returned — a draft, an unpublished post or one scheduled
 * for later is invisible from outside the admin.
 *
 * GET blog.php            → the newest live posts
 * GET blog.php?limit=3    → fewer of them
 */

declare(strict_types=1);

require_once __DIR__ . '/admin/lib.php';

header('Content-Type: application/json');
/* short, so a post that falls due is not held back by a cache */
header('Cache-Control: public, max-age=30');

$limit = (int) ($_GET['limit'] ?? 6);
$limit = max(1, min($limit, 12));

$posts = [];

foreach (blog_live_posts($limit) as $post) {
    $date = $post['publish_at'] ?: $post['created_at'];

    $posts[] = [
        'slug'     => (string) $post['slug'],
        'title'    => (string) $post['title'],
        'subtitle' => (string) ($post['subtitle'] ?? ''),
        'body'     => (string) $post['body'],
        'image'    => $post['image_path'] ? (string) $post['image_path'] : null,
        'date'     => date('j M Y', strtotime((string) $date)),
        'minutes'  => blog_read_minutes((string) $post['body']),
    ];
}

echo json_encode(['posts' => $posts]);
