<?php
/**
 * Whose sale an imported list is — the office's box, on admin/import.php only.
 *
 * One box takes either kind of code, the way the website's does: the prefix says
 * which it is, MD a dealer and MX a distributor, and a code is only ever one of
 * the two. Left empty, the sale is the company's own and pays nobody.
 *
 * Expects $partnerCode.
 */

declare(strict_types=1);
?>

<div class="field">
  <label for="partner_code">Book these sales to (optional)</label>
  <input id="partner_code" name="partner_code" type="text" maxlength="20"
         value="<?= e((string) $partnerCode) ?>"
         placeholder="MD00001 or MX00001" autocomplete="off" spellcheck="false">
  <span class="field-hint">
    A dealer's or a distributor's code. A dealer's also pays their distributor the override,
    so there is no need to name both. Leave it empty for a sale the company made itself.
  </span>
</div>
