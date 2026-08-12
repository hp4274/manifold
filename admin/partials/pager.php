<?php
/**
 * Page links under a table.
 *
 * Expects $pagerPage, $pagerPages, $pagerTotal, $pagerFrom, $pagerTo and
 * $pagerBase — the URL of the list without a page parameter, already carrying
 * whatever else it needs (type, status).
 *
 * Nothing is drawn when everything fits on one page.
 */

declare(strict_types=1);

if ($pagerPages < 2) {
    return;
}

/** The URL of one page. */
$pagerUrl = static function (int $number) use ($pagerBase): string {
    return $pagerBase . (strpos($pagerBase, '?') === false ? '?' : '&') . 'page=' . $number;
};

/* first, last, and a window around where we are — with a gap where numbers are
   skipped, so a long list does not grow a hundred links */
$pagerNumbers = [];

foreach (range(1, $pagerPages) as $number) {
    if ($number === 1 || $number === $pagerPages || abs($number - $pagerPage) <= 1) {
        $pagerNumbers[] = $number;
    }
}
?>
<nav class="pager" aria-label="Pages">
  <span class="pager__count">
    <?= (int) $pagerFrom ?>–<?= (int) $pagerTo ?> of <?= (int) $pagerTotal ?>
  </span>

  <span class="pager__links">
    <?php if ($pagerPage > 1): ?>
      <a class="pager__step" href="<?= e($pagerUrl($pagerPage - 1)) ?>" rel="prev">
        <i class="bi bi-chevron-left" aria-hidden="true"></i> Previous
      </a>
    <?php else: ?>
      <span class="pager__step is-off"><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</span>
    <?php endif; ?>

    <?php $pagerLast = 0; ?>
    <?php foreach ($pagerNumbers as $number): ?>
      <?php if ($pagerLast && $number > $pagerLast + 1): ?>
        <span class="pager__gap" aria-hidden="true">…</span>
      <?php endif; ?>

      <?php if ($number === $pagerPage): ?>
        <span class="pager__page is-here" aria-current="page"><?= $number ?></span>
      <?php else: ?>
        <a class="pager__page" href="<?= e($pagerUrl($number)) ?>"><?= $number ?></a>
      <?php endif; ?>

      <?php $pagerLast = $number; ?>
    <?php endforeach; ?>

    <?php if ($pagerPage < $pagerPages): ?>
      <a class="pager__step" href="<?= e($pagerUrl($pagerPage + 1)) ?>" rel="next">
        Next <i class="bi bi-chevron-right" aria-hidden="true"></i>
      </a>
    <?php else: ?>
      <span class="pager__step is-off">Next <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
    <?php endif; ?>
  </span>
</nav>
