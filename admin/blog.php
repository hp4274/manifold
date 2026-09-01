<?php
/**
 * Blog posts: write, schedule, publish, pull down, delete.
 *
 * Images go to assets/images/blog/ rather than admin/uploads/, because the
 * public site has to be able to load them — uploads/ is deliberately closed.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$user       = require_login();
$pageTitle  = 'Blog';
$pageLead   = 'What the website shows above the closing call to action.';
$activeType = 'blog';

const BLOG_IMAGE_DIR = __DIR__ . '/../assets/images/blog';
const BLOG_IMAGE_WEB = 'assets/images/blog';

$error   = '';
$editing = null;

/* survives the redirect that follows every action */
$flash = (string) ($_SESSION['blog_flash'] ?? '');
unset($_SESSION['blog_flash']);

/** Finish an action: remember what happened and start again with a clean form. */
function blog_done(string $message, string $to = 'blog'): void
{
    $_SESSION['blog_flash'] = $message;

    header('Location: ' . $to);
    exit;
}

/** Moves an uploaded picture into assets/images/blog and returns its web path. */
function store_blog_image(): ?string
{
    if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > UPLOAD_MAX_BYTES) {
        return null;
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if (!isset($allowed[$mime])) {
        return null;
    }

    if (!is_dir(BLOG_IMAGE_DIR)) {
        mkdir(BLOG_IMAGE_DIR, 0775, true);
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($file['tmp_name'], BLOG_IMAGE_DIR . '/' . $name)) {
        return null;
    }

    return BLOG_IMAGE_WEB . '/' . $name;
}

/** Removes a post's picture from disk, if this screen is the one that put it there. */
function delete_blog_image(int $id): void
{
    $stmt = db()->prepare('SELECT image_path FROM blog_posts WHERE id = ?');
    $stmt->execute([$id]);
    $image = (string) ($stmt->fetchColumn() ?: '');

    if ($image !== '' && strpos($image, BLOG_IMAGE_WEB . '/') === 0 && is_file(__DIR__ . '/../' . $image)) {
        unlink(__DIR__ . '/../' . $image);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $title    = trim((string) ($_POST['title'] ?? ''));
        $subtitle = trim((string) ($_POST['subtitle'] ?? ''));
        $body     = trim((string) ($_POST['body'] ?? ''));
        $status   = (string) ($_POST['status'] ?? 'draft');
        $when     = trim((string) ($_POST['publish_at'] ?? ''));

        $publishAt = $when === '' ? null : date('Y-m-d H:i:s', strtotime($when));

        /* a schedule that has already elapsed is only wrong if it is new: the
           post it belongs to is live, and saving a typo should not be refused */
        $unchangedSchedule = null;

        if ($id > 0) {
            $stmt = db()->prepare('SELECT publish_at FROM blog_posts WHERE id = ?');
            $stmt->execute([$id]);
            $unchangedSchedule = $stmt->fetchColumn() ?: null;
        }

        if ($title === '' || $body === '') {
            $error = 'A post needs a title and a body.';
        } elseif (!in_array($status, BLOG_STATUSES, true)) {
            $error = 'Unknown status.';
        } elseif ($status === 'scheduled' && $publishAt === null) {
            $error = 'Pick the date and time it should go live.';
        } elseif ($status === 'scheduled' && strtotime((string) $publishAt) <= time()
                  && $publishAt !== $unchangedSchedule) {
            $error = 'That moment has already passed. Pick one in the future, '
                . 'or use Publish to put the post up now.';
        } else {
            /* an elapsed schedule is what "published" means */
            if ($status === 'scheduled' && $publishAt !== null && strtotime($publishAt) <= time()) {
                $status = 'published';
            }

            $image = store_blog_image();

            if ($id > 0) {
                $sql = 'UPDATE blog_posts
                           SET slug = ?, title = ?, subtitle = ?, body = ?, status = ?, publish_at = ?'
                     . ($image ? ', image_path = ?' : '')
                     . ' WHERE id = ?';

                $params = [blog_slug($title, $id), $title, $subtitle ?: null, $body, $status, $publishAt];

                if ($image) {
                    $params[] = $image;
                }

                $params[] = $id;
                db()->prepare($sql)->execute($params);

                blog_done('Post updated.');
            } else {
                db()->prepare(
                    'INSERT INTO blog_posts (slug, title, subtitle, body, image_path, status, publish_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    blog_slug($title), $title, $subtitle ?: null, $body, $image,
                    $status, $publishAt, (int) $user['id'],
                ]);

                blog_done('Post created.');
            }
        }
    } elseif (in_array($action, ['published', 'unpublished', 'draft'], true)) {
        db()->prepare('UPDATE blog_posts SET status = ?, publish_at = CASE WHEN ? = \'published\' THEN COALESCE(publish_at, NOW()) ELSE publish_at END WHERE id = ?')
            ->execute([$action, $action, $id]);

        blog_done('Post marked ' . blog_status_label($action) . '.');
    } elseif ($action === 'drop_image') {
        delete_blog_image($id);
        db()->prepare('UPDATE blog_posts SET image_path = NULL WHERE id = ?')->execute([$id]);

        /* back to the post being edited, not to a blank form */
        blog_done('Picture removed.', 'blog?edit=' . $id);
    } elseif ($action === 'delete') {
        delete_blog_image($id);
        db()->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([$id]);

        blog_done('Post deleted.');
    } else {
        $error = 'Unknown action.';
    }
}

if (($_GET['edit'] ?? '') !== '') {
    $stmt = db()->prepare('SELECT * FROM blog_posts WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$posts = db()->query(
    'SELECT b.*, a.name AS author
       FROM blog_posts b
       LEFT JOIN admin_users a ON a.id = b.created_by
      ORDER BY COALESCE(b.publish_at, b.created_at) DESC'
)->fetchAll();

$counts = ['live' => 0, 'scheduled' => 0, 'draft' => 0];

foreach ($posts as $post) {
    $state = blog_state($post);

    if ($state === 'published') {
        $counts['live']++;
    } elseif ($state === 'scheduled') {
        $counts['scheduled']++;
    } elseif ($state === 'draft') {
        $counts['draft']++;
    }
}

require __DIR__ . '/partials/layout-top.php';
?>

<?php if ($flash !== ''): ?>
  <p class="alert alert--ok"><?= e($flash) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><?= e($error) ?></p>
<?php endif; ?>

<div class="tiles">
  <span class="tile">
    <span class="eyebrow">Live on the site</span>
    <strong><?= (int) $counts['live'] ?></strong>
    <span class="tile__stats"><span class="tile__stat">visible above the call to action</span></span>
  </span>
  <span class="tile">
    <span class="eyebrow">Scheduled</span>
    <strong><?= (int) $counts['scheduled'] ?></strong>
    <span class="tile__stats"><span class="tile__stat">go live on their own</span></span>
  </span>
  <span class="tile">
    <span class="eyebrow">Drafts</span>
    <strong><?= (int) $counts['draft'] ?></strong>
    <span class="tile__stats"><span class="tile__stat">nobody outside can see them</span></span>
  </span>
</div>

<div class="panel panel--open">
  <div class="panel__head">
    <h2><?= $editing ? 'Edit post' : 'Write a post' ?></h2>
    <?php if ($editing): ?>
      <a class="eyebrow" href="blog">Cancel and start a new one</a>
    <?php endif; ?>
  </div>

  <form method="post" class="panel__body" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $editing ? (int) $editing['id'] : 0 ?>">

    <div class="field">
      <label for="title">Title</label>
      <input id="title" name="title" type="text" maxlength="200" required
             value="<?= e($editing['title'] ?? '') ?>">
    </div>

    <div class="field">
      <label for="subtitle">Subtitle</label>
      <input id="subtitle" name="subtitle" type="text" maxlength="300"
             value="<?= e($editing['subtitle'] ?? '') ?>">
      <span class="field-hint">One line under the title on the card.</span>
    </div>

    <div class="field">
      <label for="body">Body</label>
      <textarea id="body" name="body" rows="10" required><?= e($editing['body'] ?? '') ?></textarea>
      <span class="field-hint">Plain text. Leave a blank line between paragraphs — the website turns
        each one into its own paragraph.</span>
    </div>

    <div class="field">
      <label for="image">Picture</label>

      <?php if (!empty($editing['image_path'])): ?>
        <div class="image-current">
          <img src="<?= SITE_URL . '/' . e((string) $editing['image_path']) ?>" alt="">
          <div class="image-current__side">
            <code><?= e(basename((string) $editing['image_path'])) ?></code>
            <span class="field-hint">This stays as it is unless you choose a new file below.</span>
          </div>
          <button type="submit" form="dropPicture" class="image-current__drop"
                  title="Remove this picture" aria-label="Remove this picture">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </div>
      <?php endif; ?>

      <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp">
      <span class="field-hint">JPG, PNG or WebP, landscape reads best.</span>
    </div>

    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <?php foreach (BLOG_STATUSES as $option): ?>
          <option value="<?= e($option) ?>" <?= ($editing['status'] ?? 'draft') === $option ? 'selected' : '' ?>>
            <?= e(blog_status_label($option)) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="publish_at">Go live at</label>
      <input id="publish_at" name="publish_at" type="datetime-local" data-limit="future"
             value="<?= $editing && $editing['publish_at']
                        ? e(date('Y-m-d\TH:i', strtotime((string) $editing['publish_at'])))
                        : '' ?>">
      <span class="field-hint">Required for <strong>Scheduled</strong> — the post appears on the site by
        itself once this moment passes. Leave blank otherwise.</span>
    </div>

    <button type="submit" class="btn btn--primary"><?= $editing ? 'Save changes' : 'Create post' ?></button>
  </form>

  <?php if (!empty($editing['image_path'])): ?>
    <form method="post" id="dropPicture" hidden
          data-confirm="Remove the picture from &ldquo;<?= e((string) $editing['title']) ?>&rdquo;?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="drop_image">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel__head">
    <h2>All posts</h2>
    <span class="eyebrow"><?= count($posts) ?> in total</span>
  </div>

  <?php if (!$posts): ?>
    <p class="empty">Nothing written yet.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table data-table--blog">
        <colgroup>
          <col style="width:38%">
          <col style="width:16%">
          <col style="width:18%">
          <col style="width:28%">
        </colgroup>
        <thead>
          <tr>
            <th>Post</th>
            <th>Status</th>
            <th>Date</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($posts as $post): ?>
            <?php $state = blog_state($post); ?>
            <tr>
              <td>
                <div class="cell-stack">
                  <strong><?= e($post['title']) ?></strong>
                  <?php if ($post['subtitle']): ?>
                    <span class="cell-sub"><?= e($post['subtitle']) ?></span>
                  <?php endif; ?>
                  <span class="cell-sub">
                    /<?= e($post['slug']) ?>
                    <?= $post['author'] ? ' · ' . e($post['author']) : '' ?>
                  </span>
                </div>
              </td>
              <td>
                <div class="cell-stack">
                  <span class="pill pill--blog-<?= e($state) ?>"><?= e(blog_status_label($state)) ?></span>
                  <?php if ($post['status'] === 'scheduled' && $state === 'published'): ?>
                    <span class="cell-sub">its date has passed</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="cell-stack">
                  <span class="cell-sub"><?= e(format_datetime($post['publish_at'] ?: $post['created_at'])) ?></span>
                  <span class="cell-sub"><?= (int) blog_read_minutes((string) $post['body']) ?> min read</span>
                </div>
              </td>
              <td>
                <div class="blog-actions">
                  <a class="btn btn--ghost btn--sm" href="blog?edit=<?= (int) $post['id'] ?>">Edit</a>

                  <?php if ($state !== 'published'): ?>
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                      <button type="submit" name="action" value="published" class="btn btn--primary btn--sm">Publish</button>
                    </form>
                  <?php else: ?>
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                      <button type="submit" name="action" value="unpublished" class="btn btn--ghost btn--sm">Unpublish</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($post['status'] !== 'draft'): ?>
                    <form method="post">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                      <button type="submit" name="action" value="draft" class="btn btn--ghost btn--sm">Draft</button>
                    </form>
                  <?php endif; ?>

                  <form method="post" data-confirm="Delete &ldquo;<?= e($post['title']) ?>&rdquo;? This cannot be undone.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
                    <button type="submit" name="action" value="delete" class="btn btn--danger btn--sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout-bottom.php'; ?>
