<?php
/** The single slide-over that every Details button copies its record into. */

declare(strict_types=1);
?>
<div class="drawer" id="drawer" hidden>
  <div class="drawer__overlay" data-drawer-close></div>

  <aside class="drawer__panel" role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
    <header class="drawer__head">
      <div>
        <h2 id="drawerTitle">Submission</h2>
        <p class="drawer__meta" id="drawerMeta"></p>
      </div>
      <div class="drawer__head-right">
        <?php /* applications carry a booking number; enquiries and signups do not */ ?>
        <span class="drawer__code" id="drawerCode" hidden></span>
        <span class="pill" id="drawerStatus"></span>
        <button type="button" class="drawer__close" data-drawer-close aria-label="Close details">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </div>
    </header>

    <div class="drawer__body" id="drawerBody"></div>
  </aside>
</div>
