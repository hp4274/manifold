<?php
/**
 * The button that opens the MIS export dialog, next to Add on a list panel.
 *
 * Expects $exportKind ('dealers' or 'distributors') — the dialog itself is
 * `export-modal.php`, rendered once at the bottom of the page.
 */

declare(strict_types=1);

$exportKind ??= 'dealers';
?>
<button type="button" class="btn btn--ghost btn--sm export-open" data-modal-open="exportModal">
  <i class="bi bi-download" aria-hidden="true"></i>
  Export MIS
</button>
