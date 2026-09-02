<?php
/**
 * The MIS export dialog, opened from the button on the dealers and
 * distributors panels.
 *
 * Two questions and nothing else: which period, and what to open it in. The
 * dates belong to the last period only, so they stay out of the way until it
 * is picked — a form that asks for dates it will not read is a form somebody
 * fills in twice.
 *
 * Expects $exportKind ('dealers' or 'distributors').
 */

declare(strict_types=1);

$exportKind ??= 'dealers';
$exportWho   = $exportKind === 'dealers' ? 'dealer' : 'distributor';
$exportToday = date('Y-m-d');
?>
<div class="modal-x" id="exportModal" role="dialog" aria-modal="true" aria-labelledby="exportModalTitle">
  <div class="modal-x__backdrop" data-modal-close></div>

  <div class="modal-x__card">
    <div class="modal-x__head">
      <h2 id="exportModalTitle">Export <?= e($exportWho) ?> MIS</h2>
      <button type="button" class="modal-x__close" data-modal-close aria-label="Close">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <form method="get" action="export" data-modal-download>
      <div class="modal-x__body">
        <input type="hidden" name="kind" value="<?= e($exportKind) ?>">

        <div class="field">
          <label for="exportRange">Period</label>
          <select id="exportRange" name="range" data-range-select>
            <option value="all">All time</option>
            <option value="week">Last 7 days</option>
            <option value="month">Last 30 days</option>
            <option value="custom">Custom range</option>
          </select>
          <span class="field-hint">
            Sales, commission and payouts are counted inside the period. What each partner has
            earned and been paid altogether is on every report whatever you pick.
          </span>
        </div>

        <div class="export-dates" data-range-custom hidden>
          <div class="field">
            <label for="exportFrom">From</label>
            <input id="exportFrom" type="date" name="from" max="<?= e($exportToday) ?>">
          </div>
          <div class="field">
            <label for="exportTo">To</label>
            <input id="exportTo" type="date" name="to" max="<?= e($exportToday) ?>">
          </div>
        </div>

        <fieldset class="field export-formats">
          <legend>File</legend>

          <label class="export-format">
            <input type="radio" name="format" value="xlsx" checked>
            <span class="export-format__icon"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i></span>
            <span class="export-format__text">
              <strong>Excel workbook</strong>
              <span>Laid out to read: sized columns, a frozen header, totals underneath.</span>
            </span>
          </label>

          <label class="export-format">
            <input type="radio" name="format" value="pdf">
            <span class="export-format__icon"><i class="bi bi-filetype-pdf" aria-hidden="true"></i></span>
            <span class="export-format__text">
              <strong>Printable report</strong>
              <span>PDF, the figures that matter. For sending on or filing.</span>
            </span>
          </label>

          <label class="export-format">
            <input type="radio" name="format" value="csv">
            <span class="export-format__icon"><i class="bi bi-filetype-csv" aria-hidden="true"></i></span>
            <span class="export-format__text">
              <strong>Plain CSV</strong>
              <span>Unformatted data, for loading into another system.</span>
            </span>
          </label>

        </fieldset>
      </div>

      <div class="modal-x__foot">
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary">
          <i class="bi bi-download" aria-hidden="true"></i> Download report
        </button>
      </div>
    </form>
  </div>
</div>
