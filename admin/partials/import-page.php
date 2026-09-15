<?php
/**
 * The import screen itself, shared by the office, dealers and distributors.
 *
 * The chrome around it differs — three portals, three sidebars — so this is only
 * the middle, included after each portal's own layout-top.
 *
 * Expects:
 *   $state       what import_run() returned
 *   $context     who the sale is booked to, for the line that says so
 *   $backHref    where "Cancel" and "Done" go
 *   $backLabel   what that link is called
 */

declare(strict_types=1);

$stage    = (string) $state['stage'];
$error    = (string) $state['error'];
$preview  = $state['preview'];
$result   = $state['result'];
$booksTo  = import_commission_label($context);
$isOffice = empty($context['dealer']) && empty($context['distributor']);
?>

<?php if ($error !== ''): ?>
  <p class="alert alert--error"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <?= e($error) ?></p>
<?php endif; ?>

<?php /* ---------------------------------------------------------------- 1 */ ?>
<?php if ($stage === 'upload'): ?>

  <div class="panel">
    <div class="panel__head">
      <div class="panel__head-text">
        <h2>Import clients from a spreadsheet</h2>
        <span class="eyebrow">
          A .csv or .xlsx file, up to <?= (int) IMPORT_MAX_ROWS ?> clients at a time
        </span>
      </div>
      <a class="btn btn--ghost btn--sm" href="<?= e($backHref) ?>"><?= e($backLabel) ?></a>
    </div>

    <form method="post" enctype="multipart/form-data">
      <div class="panel__body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">

        <div class="field">
          <label for="sheet">Your file</label>
          <input id="sheet" name="sheet" type="file" accept=".csv,.xlsx,.tsv,text/csv" required>
          <span class="field-hint">
            The first row has to name the columns. Full name, email and the address
            are needed for every client; everything else is optional.
            Headings are matched loosely, so "Customer Name", "Mobile No" and "Pincode" are
            all understood — and any column we do not use is left alone.
          </span>
        </div>

        <?php /* the office gets a box for whose sale this is; a dealer and a
                 distributor do not, because it can only be their own */ ?>
        <?php if (!empty($extraFields)): require $extraFields; endif; ?>

        <p class="import-books">
          <i class="bi bi-cash-coin" aria-hidden="true"></i>
          <span><strong>Commission:</strong> <?= e($booksTo) ?></span>
        </p>

        <p class="field-hint">
          Nothing is created yet. The next screen shows what is in the file — how many
          clients are ready, which rows have a problem and which have applied before —
          and you decide from there.
        </p>
      </div>

      <div class="modal-x__foot">
        <a class="btn btn--ghost" href="?template=1">
          <i class="bi bi-download" aria-hidden="true"></i> Download the template
        </a>
        <button type="submit" class="btn btn--primary">Read the file</button>
      </div>
    </form>
  </div>

<?php /* ---------------------------------------------------------------- 2 */ ?>
<?php elseif ($stage === 'preview'): ?>

  <?php
    $ready    = $preview['ready'];
    $problems = $preview['problems'];
    $skipped  = $preview['skipped'];
  ?>

  <div class="panel">
    <div class="panel__head">
      <div class="panel__head-text">
        <h2>What is in <?= e((string) $preview['file']) ?></h2>
        <span class="eyebrow">Nothing has been created yet</span>
      </div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <button type="submit" class="btn btn--ghost btn--sm">Choose another file</button>
      </form>
    </div>

    <div class="panel__body">
      <div class="tiles">
        <span class="tile">
          <span class="eyebrow">Ready to import</span>
          <strong><?= count($ready) ?></strong>
          <span class="tile__stats"><span class="tile__stat">will be created</span></span>
        </span>
        <span class="tile">
          <span class="eyebrow">Already applied</span>
          <strong><?= count($skipped) ?></strong>
          <span class="tile__stats"><span class="tile__stat">skipped, nothing changed</span></span>
        </span>
        <span class="tile">
          <span class="eyebrow">Could not be read</span>
          <strong><?= count($problems) ?></strong>
          <span class="tile__stats"><span class="tile__stat">left for you to fix</span></span>
        </span>
      </div>

      <?php if ($preview['ignored'] !== []): ?>
        <p class="alert alert--warn">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          These columns were left alone because they are not part of an application:
          <strong><?= e(implode(', ', $preview['ignored'])) ?></strong>.
          If one of those was meant to be a client's details, rename its heading and read the file again.
        </p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($ready !== []): ?>
    <div class="panel">
      <div class="panel__head">
        <div class="panel__head-text">
          <h2><?= count($ready) ?> <?= count($ready) === 1 ? 'client' : 'clients' ?> to import</h2>
          <span class="eyebrow">each one waits for the office to approve it, as a website application does</span>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Row</th><th>Name</th><th>Email</th><th>Mobile</th><th>City</th><th>Product</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ready as $row): $c = $row['columns']; ?>
              <tr>
                <td><?= (int) $row['line'] ?></td>
                <td><?= e((string) $c['full_name']) ?></td>
                <td><?= e((string) $c['email']) ?></td>
                <td><?= e((string) ($c['mobile_number'] ?? '—')) ?></td>
                <td><?= e((string) $c['city']) ?></td>
                <td><?= e(product_label((string) $c['product'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($skipped !== []): ?>
    <div class="panel">
      <div class="panel__head">
        <div class="panel__head-text">
          <h2><?= count($skipped) ?> already on the system</h2>
          <span class="eyebrow">same name, number and email as one already here &mdash; any one of the three different and the row goes in</span>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Row</th><th>Name</th><th>Email</th><th>Why</th></tr></thead>
          <tbody>
            <?php foreach ($skipped as $row): ?>
              <tr>
                <td><?= (int) $row['line'] ?></td>
                <td><?= e((string) $row['name']) ?></td>
                <td><?= e((string) $row['email']) ?></td>
                <td><?= e((string) $row['reason']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($problems !== []): ?>
    <div class="panel">
      <div class="panel__head">
        <div class="panel__head-text">
          <h2><?= count($problems) ?> <?= count($problems) === 1 ? 'row' : 'rows' ?> could not be read</h2>
          <span class="eyebrow">
            the row number is the one your spreadsheet shows — fix them there and import that file again
          </span>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Row</th><th>Name</th><th>What is wrong</th></tr></thead>
          <tbody>
            <?php foreach ($problems as $row): ?>
              <tr>
                <td><?= (int) $row['line'] ?></td>
                <td><?= e((string) $row['name']) ?: '—' ?></td>
                <td><?= e((string) $row['reason']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <form method="post">
      <div class="panel__body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm">

        <?php if ($ready === []): ?>
          <p class="empty">
            Nothing in this file can be imported. Fix the rows above and try again.
          </p>
        <?php else: ?>
          <p class="import-books">
            <i class="bi bi-cash-coin" aria-hidden="true"></i>
            <span><strong>Commission:</strong> <?= e($booksTo) ?></span>
          </p>

          <label class="field-consent" for="agreed">
            <input id="agreed" type="checkbox" name="agreed" value="1" required>
            <span>
              Each of these <?= count($ready) ?> <?= count($ready) === 1 ? 'person' : 'people' ?>
              agreed to the declaration and the terms before I put them on this list.
            </span>
          </label>

          <p class="field-hint">
            They will each be emailed to say their application arrived. Nothing is asked of them
            yet — the office approves an application first, and that is what sends the payment
            details and opens their portal.
          </p>
        <?php endif; ?>
      </div>

      <div class="modal-x__foot">
        <a class="btn btn--ghost" href="<?= e($backHref) ?>">Cancel</a>
        <?php if ($ready !== []): ?>
          <button type="submit" class="btn btn--primary">
            Import <?= count($ready) ?> <?= count($ready) === 1 ? 'client' : 'clients' ?>
          </button>
        <?php endif; ?>
      </div>
    </form>
  </div>

<?php /* ---------------------------------------------------------------- 3 */ ?>
<?php else: ?>

  <?php $created = $result['created']; ?>

  <div class="panel">
    <div class="panel__head">
      <div class="panel__head-text">
        <h2><?= count($created) ?> <?= count($created) === 1 ? 'client' : 'clients' ?> imported</h2>
        <span class="eyebrow">
          <?= count($created) === 1 ? 'They have' : 'They have each' ?> been emailed to say their
          application arrived<?= $isOffice ? '' : ', and the office has been told' ?>
        </span>
      </div>
      <a class="btn btn--primary btn--sm" href="<?= e($backHref) ?>"><?= e($backLabel) ?></a>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Booking number</th><th>Name</th><th>Email</th><th>Product</th></tr></thead>
        <tbody>
          <?php foreach ($created as $app): ?>
            <tr>
              <td><strong><?= e((string) $app['reference_code']) ?></strong></td>
              <td><?= e((string) $app['full_name']) ?></td>
              <td><?= e((string) $app['email']) ?></td>
              <td><?= e(product_label((string) $app['product'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($result['skipped'] !== [] || $result['problems'] !== []): ?>
      <div class="panel__body">
        <p class="field-hint">
          <?php if ($result['skipped'] !== []): ?>
            <?= count($result['skipped']) ?> had applied before and
            <?= count($result['skipped']) === 1 ? 'was' : 'were' ?> left alone.
          <?php endif; ?>
          <?php if ($result['problems'] !== []): ?>
            <?= count($result['problems']) ?>
            <?= count($result['problems']) === 1 ? 'row' : 'rows' ?> could not be read and
            <?= count($result['problems']) === 1 ? 'was' : 'were' ?> not imported — fix
            <?= count($result['problems']) === 1 ? 'it' : 'them' ?> in your spreadsheet and
            import that file again.
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel__body">
      <a class="btn btn--ghost" href="import">Import another file</a>
    </div>
  </div>

<?php endif; ?>
