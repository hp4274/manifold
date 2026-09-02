<?php
/**
 * The MIS export, inside one partner's drawer.
 *
 * The same two questions the list's dialog asks — which period, and what to
 * open it in — but the answer covers this partner alone. The office reads a
 * drawer when somebody rings up about their own figures, and a report they can
 * send back from there beats going out to the list to build it.
 *
 * Expects $exportKind ('dealers' or 'distributors') and $exportId.
 */

declare(strict_types=1);

$exportToday = date('Y-m-d');
$exportForm  = 'export-' . $exportKind . '-' . (int) $exportId;
?>
<div class="detail-block">
  <p class="detail-block__title">
    Export their figures
    <span class="detail-block__note">Sales, commission and payouts</span>
  </p>

  <form class="export-inline" method="get" action="export">
    <input type="hidden" name="kind" value="<?= e($exportKind) ?>">
    <input type="hidden" name="id" value="<?= (int) $exportId ?>">

    <div class="field">
      <label for="<?= e($exportForm) ?>-range">Period</label>
      <select id="<?= e($exportForm) ?>-range" name="range" data-range-select>
        <option value="all">All time</option>
        <option value="week">Last 7 days</option>
        <option value="month">Last 30 days</option>
        <option value="custom">Custom range</option>
      </select>
      <span class="field-hint">
        Sales, commission and payouts are counted inside the period. What they have earned and
        been paid altogether is on the report whatever you pick.
      </span>
    </div>

    <div class="export-dates" data-range-custom hidden>
      <div class="field">
        <label for="<?= e($exportForm) ?>-from">From</label>
        <input id="<?= e($exportForm) ?>-from" type="date" name="from" max="<?= e($exportToday) ?>">
      </div>
      <div class="field">
        <label for="<?= e($exportForm) ?>-to">To</label>
        <input id="<?= e($exportForm) ?>-to" type="date" name="to" max="<?= e($exportToday) ?>">
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

    <button type="submit" class="btn btn--primary">
      <i class="bi bi-download" aria-hidden="true"></i> Download report
    </button>
  </form>
</div>
