# to-do

Work list from `report.md` (1 September 2026 QA pass). Each item names the file, what to
change and how to check it worked. Section references point back at the report.

Already done in the QA session, nothing to do here: the `id_number` pattern (§3.1), the
`.git` exposure block and security headers (§3.2, §3.4), `inert` on the sealed payment
block (§3.3), the payment-rejection reason (§3.5), `PUBLIC_BASE_URL` so cron emails stop
linking to `http://localhost.` (§3.6), and the swallowed sign-in-code send failure
(§3.7).

---

## A. Before this is reachable from the internet

### A1. Rotate the SMTP password and get it out of Git · §3.2

`admin/config.php` line 68 carries the live Hostinger password in plain text, and `/.git`
was readable over the web, so treat it as compromised.

- [ ] Change the mailbox password in the Hostinger control panel.
- [x] Move the credentials out of the tracked file: done — `SMTP_PASS` now lives in
      the untracked `admin/config.local.php`; `admin/config.php` requires it and falls
      back to `''`.

```php
/* admin/config.php — read the secrets from an untracked file beside it */
$local = __DIR__ . '/config.local.php';
if (is_file($local)) { require $local; }        // defines SMTP_PASS etc.
```

- [x] `.gitignore` already covers it (`config.local.php`, line 24).
- [x] Kept `admin/config.php` tracked with the secret removed, so no new commit carries
      the value.
- [ ] Rewriting history is the only way to remove it from past commits. If the repo is
      private and has never been shared, rotating the password is enough on its own.

**Check:** `curl -o /dev/null -w '%{http_code}' http://localhost/manifold/.git/HEAD`
returns `404`, and `grep -r SMTP_PASS admin/config.php` no longer shows the value.

### A2. Delete the QA blog posts · §9, §10.7

`blog_posts` 2 and 3 are XSS probe posts, **published and visible on `/blog` right now**.
Post 1 is a QA test post.

- [ ] Delete them from `/admin/blog` (Delete button on each row), or:

```sql
DELETE FROM blog_posts WHERE id IN (1, 2, 3);
```

**Check:** `/blog` shows no post titled `QA …`.

### A4. Take the sign-in code out of the email subject · §4.2

`admin/emails.php:775`. The six digits appear in lock-screen notifications, in the mail
client's message list, and in `email_log.subject` for anyone with database read access.

- [x] Changed the subject to a constant; the code is already in the body.
      `admin/emails.php:774`.

```php
return send_mail($to, 'Your Manifold sign-in code',
                 email_wrap('Your sign-in code', $inner), 'otp');
```

**Check:** request a code, then
`SELECT subject FROM email_log WHERE kind='otp' ORDER BY id DESC LIMIT 1;` — no digits.

### A5. Point `PUBLIC_BASE_URL` at production · §3.6

`admin/config.php:91` is now `'http://localhost/manifold'`. That was the fix for the Friday
cron putting `http://localhost.` in every commission email, but it is the **local** address.

- [x] Set to `https://manifoldcleanenergy.co.in` (`admin/config.php:97`). Local email
      links now point at production — set it back in a local `config.local.php` override
      if that gets in the way of testing.

**Check:** `php -r "require 'admin/config.php'; echo base_url();"` on the server prints the
public address.

---

## AA. Email delivery — the biggest risk in the system · §4.13

Three of six sends failed during one QA window. The provider's replies, verbatim:

```
451 4.7.1 Ratelimit "hostinger_out_ratelimit" exceeded for key "RLaobdhmgar3my6dgxbnqkfqpg"
454 4.3.0 Try again later
```

`send_mail()` writes `email_log.ok = 0` and returns `false`. Nothing retries, nothing
queues, and nothing reads that column back. The lifecycle sends five to eight emails per
applicant plus one per sign-in, from a single shared mailbox.

### AA1. Retry a failed send

- [ ] Write the failed message to a table on failure.
- [ ] A cron re-sends it a few times with a widening gap, then gives up and marks it dead.
      A `4xx` SMTP reply is explicitly temporary — "try again later" is what the server said.

### AA2. Show failures to the office

- [ ] A "mail that did not go" list in the admin, reading `email_log WHERE ok = 0`. Today
      the only pointer is `admin/partials/mail-flash.php:20`, which says to open the
      database by hand, and only fires on admin-triggered sends.

**Check:** `SELECT * FROM email_log WHERE ok = 0` — those rows are visible in the interface.

### AA3. Move transactional mail off the shared mailbox

- [ ] SES, Postmark or Brevo. A mailbox meant for a person to type into is not a
      transactional sender, and the cap has already been reached in testing. This is the
      real fix; AA1 and AA2 make the failure visible in the meantime.

### AA4. Put the UPI id beside the QR code · §4.14

The payment email's only instrument is an inline image, with _"Not showing? Open the QR code
on our website"_ as the fallback. Outlook and Gmail block images from an unknown sender by
default, and **nobody can scan a QR code on the phone they are reading the email on.**

- [ ] Add the UPI id as selectable text under the QR.
- [ ] Add a `upi://pay?pa=…&am=3500&cu=INR` link — one tap on a phone, opens the bank app.
- [ ] Add the account number and IFSC for anyone who does not use UPI.

---

## B. Cheap, high value

### B1. Turn compression and caching on · §4.1, §6.1

The `.htaccess` rules are already written and inert. `C:\xampp\apache\conf\httpd.conf`:

- [x] Uncommented `LoadModule deflate_module modules/mod_deflate.so` (`httpd.conf:111`).
- [x] Uncommented `LoadModule expires_module modules/mod_expires.so` (`httpd.conf:115`).
      `httpd -t` says `Syntax OK`; the old file is kept as `httpd.conf.bak-20260901`.
- [ ] **Still to do:** restart Apache. It is not installed as a Windows service here, so
      `Restart-Service` did nothing — press Stop then Start on Apache in the XAMPP Control
      Panel. Until then the running server has neither module and the check below fails.
- [ ] Confirm the same two modules are enabled on the production host.

**Check:**

```
curl -H 'Accept-Encoding: gzip' -o /dev/null -D - \
  http://localhost/manifold/assets/css/style.css | grep -i content-encoding
```

should print `Content-Encoding: gzip`, and the home page should drop from ~1,394 KB to
roughly 1,030 KB (about 360 KB of text saved).

### B2. `width`, `height` and `loading="lazy"` on every image · §6.2, §6.3

Not one `<img>` on the site has any of the three. This is the direct cause of layout shift.

- [x] Intrinsic `width` and `height` on all 51 `<img>`, read out of the image files
      themselves. The one on `portal/status.php` is an uploaded QR of unknown size, so it
      reads its own dimensions with `getimagesize()` at render time.
- [x] `loading="lazy"` on everything except the header logo, which is the only `<img>`
      above the fold on any page — the heroes are CSS backgrounds, not images.
- [x] `srcset` on the four hardest-shrunk images, with `sizes` set from the fixed CSS box
      each one sits in. New variants beside the originals:
      `manifold-360w.webp` 117 KB → 19 KB, `manifold-white-240w.webp` 222 KB → 9 KB,
      `K7-256w.webp` 75 KB → 6 KB, `stove-2-640w.webp` 187 KB → 39 KB. The logos are on
      every page, so that is the largest share of it.

Largest offenders: `stove-2.webp` 187 KB, `sdfgnfdsesdf.webp` 161 KB, `manifold.webp`
118 KB, `faq-tuktuk-portrait.webp` 217 KB.

**Check:** the audit script reports `noDims=0` and `noLazy` only for the hero.

### B3. Settle `.com` vs `.co.in` · §4.3

Every public page and the email footer say `info@manifoldcleanenergy.com`. `MAIL_FROM`,
`MAIL_REPLY_TO`, the SMTP account and the office recipients all say `.co.in`. Mail arrives
from one address while the site tells customers to write to the other.

- [x] Decided: `.co.in`, the domain the mail actually sends from.
- [x] Rewrote all 39 `.com` occurrences across 14 files to `.co.in`; the grep now returns
      47 hits, all `.co.in`.
- [x] `admin/mailer.php:91` now derives the `Message-ID` domain from `MAIL_FROM`, so the
      two cannot drift apart again.

### B4. Fix the raffle pool count · §11.3

The popup prints _"3 applicants are in the next draw … and a past winner does not go back
in."_ `raffle_eligible_count()` (`admin/raffle-lib.php:269`) does not exclude past winners,
so the number contradicts the rule beside it. With one winner recorded, the honest figure
is 2.

- [x] Added the exclusion to `raffle_eligible_count()` (`admin/raffle-lib.php:271`), which
      fixes both callers — the admin page and the public popup's `poolSize`. The count went
      from 3 to 2, which is the honest figure with one winner recorded.

```sql
SELECT COUNT(*) FROM applications a
 WHERE a.booking_paid_at IS NOT NULL AND a.status <> 'rejected'
   AND a.id NOT IN (SELECT application_id FROM raffle_winners)
```

- [x] Not needed — the number was fixed instead of the sentence.

### B5. Fix the raffle countdown copy · §10.4

With `raffle_enabled = 0`, `admin/raffle` still says _"GOES PUBLIC IN 13d 02h 57m"_. It
will not go public at all until the toggle is on.

- [x] With the raffle off, the clock reads "Switched off · Not counting down · Turn the
      raffle on to start the countdown", the note below it stops promising a reveal date, and
      the warning banner names the toggle rather than the missing first-draw date.

---

## C. The apply form — conversion and clarity

### C1. Stop disabling the submit button · §4.7

`assets/js/main.js:1348`. On a sixty-field form with the consent boxes at the bottom,
someone who misses one presses a dead button. The `title` tooltip needs a hover, so on a
phone there is no feedback at all, and a `disabled` button is off the tab order.

- [x] Deleted `button.disabled = !ready;` (`assets/js/main.js:1353`); the `is-locked` class
      keeps the look, and `cursor` is back to `pointer` for it so the button reads as
      pressable.
- [x] Pressing it now runs `apply.js`'s own validation, which marks every wrong field red,
      lists them in the new summary banner and raises the toast.

**Check:** on a phone-width viewport, pressing Submit with the boxes unticked scrolls to
them and turns them red.

### C2. Make Approve ask before it emails · §4.8

`admin/partials/row-actions.php:38`. Approving emails a stranger a ₹3,500 payment demand
and opens their portal. Rejecting, which sends nothing, already asks. That is backwards.

- [x] Added to `admin/partials/row-actions.php:32` — and to the same Approve button in the
      Details drawer (`admin/partials/drawer-source.php:40`), which had the same asymmetry.

```php
<form method="post" action="status.php"
      data-confirm="Approve <?= e(record_title($rowType, $row)) ?>? They are emailed the
                    payment details and their portal opens.">
```

### C3. Convert apply forms to Multi-Step Stepper Forms · §5

`apply-stove.html` and `apply-tuktuk.html` currently display ten `.form-step` sections (~60 fields) in a single long scroll page.

- [x] Both pages are wizards over the twelve `.form-step` sections they already had — one
      on screen at a time with a fade-and-lift transition, Previous / Next below, and Next
      refusing to move on until the step it is leaving validates. Built entirely in
      `assets/js/apply.js`; the markup is untouched, so without JavaScript the page is still
      the long scroll it always was. Enter advances instead of submitting early.
- [x] Sticky bar and counter reading e.g. "Step 3 of 12: Residential address", with an
      `aria-live` region so the step change is announced.
- [x] Saved on `input` (debounced 400 ms) and on `change`, restored on load with a toast
      saying how many answers came back, cleared on a successful submission. Files, hidden
      fields and the honeypot are never stored.
- [x] Banner above the form: "10 fields need attention", one link per field, each naming
      the field and what is wrong with it. Clicking one opens the step it lives on and puts
      the cursor in it.
- [x] Messages now name the field and the fault: "Full name is needed.", "Choose a
      gender.", "Choose a file for Residence proof.", "That is not a complete email address —
      it needs an @ and a domain.", and length messages that give the actual limit.

**Check:** Navigating steps on both `apply-stove.html` and `apply-tuktuk.html` works as a step-by-step wizard, validates each step, saves draft progress, and displays specific error summaries on submit failure.

### C4. Country names on the dial-code selector · §4.9

`apply-stove.html:340` — 170 options reading `+1`, `+7`, `+20`, `+27` … with no country
names, sorted numerically.

- [x] All four dial selects on the two pages (`mobile_code` and `alt_mobile_code`) now read
      `India +91`, `Nepal +977` and sort by country. Same 170 codes, same posted values,
      India still selected.
- [x] `country` is a select of the same 172 countries, India selected — matching
      `nationality` beside it, and keeping "india" / "Bharat" / "IN" out of one column.

---

## D. Accessibility

### D1. The three contrast tokens · §7.1

Verified ratios, all below the 4.5:1 that WCAG AA asks for normal text:

| Token                      | Now       | Ratio | Replace with                      | New ratio                       |
| -------------------------- | --------- | ----- | --------------------------------- | ------------------------------- |
| `--muted`                  | `#8499ac` | 2.94  | `#5c7389`                         | 4.92 on white, 4.66 on `--tint` |
| `--accent-2` as small text | `#17b0a6` | 2.69  | new `--accent-ink-2: #0c7a74`     | 5.18                            |
| white on `--accent`        | `#4bb453` | 2.64  | white on `--accent-ink` `#2e7d34` | 5.12                            |

- [x] Change `--muted` in the `:root` block of `assets/css/style.css`.
- [x] Add `--accent-ink-2` and use it wherever `--accent-2` sets text at `--t-micro` (11px)
      — the eyebrow labels "Overview", "Reach us", "Sign in", "Application". Leave
      `--accent-2` alone as a fill, a border and for large headings.
- [x] Use `--accent-ink` as the background for the Subscribe button and the green badges.
- [x] Update `.claude/theme.md` to match — CLAUDE.md asks for the tokens to stay in sync.
- [ ] **Look at the result.** This touches all thirteen sections of the stylesheet.

### D2. Skip link · §5

No page has one, and the header is a topbar plus a full navigation, so a keyboard user
passes about a dozen links before reaching content on every page.

- [x] Add as the first element in `<body>` on all ten public pages and the portal partials:

```html
<a class="skip-link" href="#main">Skip to content</a>
```

```css
.skip-link {
  position: absolute;
  left: -9999px;
}
.skip-link:focus {
  left: 16px;
  top: 16px;
  z-index: 2000;
  padding: 10px 16px;
  background: var(--white);
  color: var(--ink);
  border-radius: var(--radius-sm);
}
```

- [x] Give the `<main>` element `id="main"` (there is exactly one per page already).

### D3. Heading levels · §5

`h1 → h3` and `h2 → h4` on `/`, `/stove`, `/tuktuk`; `h1 → h4` on `/blog` and
`/coming-soon`; `h2 → h4` on both apply pages.

- [x] Renumber so no level is skipped. Size comes from CSS, not from the tag.

### D4. Focus ring on the `TukTuk` nav link · §5

> Root cause was not a missing selector: `<details>` keeps a closed menu's links
> out of the tab order, so the link could never take focus. `.nav-dropdown__menu`
> now uses `pointer-events` instead of `visibility`, and `main.js` opens the
> dropdown on `focusin` and closes it on `focusout`.


No outline and no box-shadow on focus on `/stove`, `/apply-stove`, `/contact`, `/portal/`.
Every other control tested has one, so this looks like a missing selector.

- [x] Find the rule that covers the other nav links and include this one.

### D5. Touch targets · §5

Footer social icons 26×26 px, the email link 32×32, "See the prices" 120×32.

- [x] Pad to at least 44×44 px. Padding, not font size — the design does not need to change.

### D6. Raffle popup: `role` and `aria-modal` · §11.3

> Already matched — `assets/js/main.js` builds the card with
> `role="dialog" aria-modal="true" aria-labelledby="raffleTitle"`.


It moves focus to its close button, which is right, but carries no `role="dialog"` and no
`aria-modal`. The admin's own confirmation dialog (`admin/assets/admin.js:64`) sets
`role="alertdialog" aria-modal="true"`.

- [x] Match it.

### D7. `lang`, preheader and dark mode in the email wrapper · §4.15

> `email_wrap()` takes an optional third `$preheader`; with none it uses the
> first 140 characters of the message body, so every template got a real preview
> line without touching twenty call sites.


All twenty templates lack all three. One change in `email_wrap()` covers every one.

- [x] `<html lang="en">` — currently no template sets it.
- [x] A preheader: a hidden line at the very top of the body that becomes the inbox preview.
      Every template currently opens with the masthead table, so the preview shows the logo
      block instead of a sentence chosen for it.

```html
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
  <?= e($preheader) ?>
</div>
```

- [x] A `prefers-color-scheme: dark` block setting an explicit background and colour. Gmail
      and Apple Mail auto-invert a white card, and the navy `#0f2c4d` body text is exactly
      the colour that comes out badly.

---

## E. Data integrity and abuse

All ten worked through on 2 September 2026. The parts with logic worth breaking — both
signatures, the reference format, the recorded rate and the uniform sign-in reply — have a
runnable check at `admin/tests/section-e.php` (`php admin/tests/section-e.php`, 21 checks, no
database writes). What is left open under each heading below is named there.

### E1. Record the commission rate · §10.1

`commission_lines.rate` is `0.00` on every row while `amount` is correct. `admin/submit.php`
freezes the price and the split onto the sale so _"raising a rate later must not rewrite
what this sale was worth"_ — but the rate that produced the amount is the one part not
written down.

- [x] `commission_write_lines()` (`admin/lib.php:1874`) now writes the share of the payment the
      flat amount came to — `amount / base * 100`, rounded to the column's two decimals and
      capped at its `decimal(5,2)` ceiling. A delivery commission of ₹3,000 on ₹16,500 records
      `18.18`. There is no percentage behind a flat amount, so this is the rate the line was
      worth rather than a rate anybody set, which is the part that was missing.

### E2. Rate-limit the public form endpoint · §4.5

Six newsletter posts back to back were all accepted. Each accepted application, contact
message and newsletter signup sends mail, so this is both database spam and a way to burn
the sending reputation of a shared mailbox.

- [x] `throttled()` in `admin/submit.php:78`, five an hour per form and per IP. No new table:
      `applications`, `contact_messages` and `newsletter_subscribers` all carry `ip_address`
      and `created_at`, so the count is the record itself and there is nothing to prune. A
      request arriving with no address behind it is not throttled.

**Check:** done — six newsletter posts in a row, the sixth answered
`429 {"ok":false,"message":"That is a lot of submissions from one connection…"}`. The five
test rows were deleted afterwards.

### E3. Make the portal sign-in reply uniform · §4.6

`portal/lib.php:228`. The form distinguishes three states for any address typed at it —
unknown, application pending, active customer — which with no per-IP throttle is a workable
customer-list harvester.

- [x] `OTP_SENT_NOTICE` (`portal/lib.php:191`) is the one answer to every address, and
      `issue_otp()` returns `''` for an address it does not know — the code step is reached
      either way, and a code typed there without one having gone out simply does not match.
- [x] `send_application_waiting_email()` (`admin/emails.php:682`) carries the "still with our
      team" explanation to the mailbox instead of to the page, and says what to do if the
      reader did not ask for it.
- [ ] **Known gap:** an error string is still returned when *our own* send fails, so while
      the mailbox is over its cap a registered address gets "we could not send the code" and
      an unknown one gets the notice. Fixing it means either stranding a real applicant on a
      code that never arrives (what §3.7 was about) or queuing the send — AA1. Left as is
      deliberately; AA1 closes it.

**Check:** done — posting `definitely-not-registered@example.com` at `/portal/` answers
_"If that address is registered with us, a six-digit code is on its way. It is valid for 10
minutes."_

### E4. Check the stock-order reference · §10.2

`stock_orders.reference` ties a stock order to a bank transaction and accepts anything —
the two existing rows read `34t5yuio9` and `tcvgbhjkl,;.'/`.

- [x] Put in `stock_order_create()` (`admin/lib.php:1346`) rather than in `distributor/stock.php`,
      because the dealer page calls the same function and had the same hole. At least six
      letters or digits, starting with one, and nothing else but spaces, hyphens, slashes and
      underscores — every bank names its reference differently, so only shape is checked.
      Blank is still allowed: the proof of payment is the required part.
      `34t5yuio9` passes, `tcvgbhjkl,;.'/` does not.

### E5. Newsletter unsubscribe header · §4.11

`admin/mailer.php` sets no `List-Unsubscribe`. Gmail and Yahoo both require one-click
unsubscribe on bulk mail; without it the newsletter starts landing in spam and drags
transactional mail from the same domain with it.

- [x] `bulk_headers()` (`admin/mailer.php:96`) adds both headers to any send whose `kind`
      starts `newsletter`, and nothing else — nobody unsubscribes from their own receipt.
- [x] `unsubscribe.php` at the site root, answering both the mail client's one-click POST and
      a person's click. The address is proved by an HMAC token beside it, so nobody can take
      somebody else off the list by typing their address into the URL, and an address that was
      never on the list gets the same answer as one that was.
- [x] The row is marked `rejected` with a note rather than deleted, so the office can see
      somebody left and a re-subscribe does not silently welcome them back.
- [x] The welcome email's footer is now that link instead of _"reply with unsubscribe"_.

**Check:** done — `POST /unsubscribe?e=…&t=…` returned 200 and the row went to
`rejected` / `"Unsubscribed from the email link."`; a bad token returns 400. Test row deleted.

### E5i. Guard the zero-amount emails · §11.1

Rendering the templates produced _"Reminder: the booking payment of ₹0.00 is due"_ and
_"Your referral reward of ₹0.00 is on its way"_. Neither is reachable from the interface —
the reminder bell only renders at `booking_pending` or `delivery_pending` — but
`payment.php?action=remind` is reachable directly.

- [x] Both guards sit in the email functions themselves — `send_payment_reminder_email()` and
      `send_referral_paid_email()` in `admin/emails.php` — so every caller is covered, not just
      the one the report named. `payment.php`'s `remind` action also answers the office with
      `409 "Nothing is outstanding on this application"` rather than silently doing nothing.

### E5ii. Speed up `/portal/` · §11.4

Three times slower than any other page under load: p50 231 ms against 93 ms for the home
page, p99 846 ms. It starts a session and calls `portal_roles()`, which queries
`applications`, `dealers` and `distributors` before deciding anything. It is also the page a
customer arrives at from a payment email.

- [x] Neither, in the end: `portal_roles()` already reads the session for the applicant and
      only queries for a dealer or a distributor whose id is in the session. What it did do was
      re-run those queries on every call, and `/portal/` calls it twice before the chrome asks
      again. `dealer_user()` and `distributor_user()` now hold the row for the rest of the
      request (`dealer/lib.php:19`, `distributor/lib.php:16`).
- [ ] Not the session, deliberately: a dealer switched off mid-session has to stop counting as
      one on the very next page, which is the reason that lookup is a query and not a flag.
- [ ] **Not re-measured under load.** The p50 figure came from a concurrency run that has not
      been repeated.

### E6. Contact form: submit the way the apply form does · §4.10

The contact form does a full page POST and returns to `/contact?error=1` with an **empty
form** and a generic toast. `admin/submit.php` already answers JSON when asked.

- [x] `main.js:117` now intercepts every `form[action*="submit.php"]` except `#applyForm`,
      which covers the footer newsletter box on all ten pages as well as the contact form.
      A refusal raises the server's own sentence as a toast and leaves everything typed on
      screen; a success resets the form. The markup is untouched — `action`, `method` and the
      `return` field are all still there, so without JavaScript both forms post the old way and
      land on the old redirect.

### E7. Harden the admin login throttle · §4.12

The throttle counts attempts per email address only, so spraying one common password across
many addresses is not slowed at all.

- [x] Two counts now, either enough to stop: 8 per email in 15 minutes as before, and 20 per
      IP in the same window (`admin/login.php:25`). The per-email count stays so a shared office
      NAT cannot lock a colleague out over somebody else's fumbled password.

### E8. Server banner · §4.12

`Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12`.

- [x] Both set — they live in `C:\xampp\apache\conf\extra\httpd-default.conf` (lines 55 and 65),
      not `httpd.conf`, which includes it. `httpd -t` says `Syntax OK`; the old file is kept as
      `httpd-default.conf.bak-20260902`.
- [ ] **Still to do:** restart Apache from the XAMPP Control Panel — same restart B1 is waiting
      on. Until then the running server still answers `Apache/2.4.58 (Win64) …`.
- [ ] Set the same two on the production host.

### E9. HTTPS, in production · §8

Could not be tested locally.

- [x] `Secure` was already following `$_SERVER['HTTPS']`. It now also follows
      `X-Forwarded-Proto` and port 443 (`admin/lib.php:82`) — behind Cloudflare or a load
      balancer the request reaching PHP is plain HTTP even though the visitor is on TLS, and
      without that the production session cookie would have gone out in the clear.
- [x] HSTS is in `.htaccess`, guarded by `"expr=%{HTTPS} == 'on'"` so it is only ever sent over
      TLS. That is what makes it safe to keep in a file deployed everywhere: sent once on a
      plain-HTTP local install it would pin the host to `https://` in the browser with no way
      back but clearing the HSTS store.
- [ ] Check for mixed content once the site is behind TLS. Still untestable here.

### E10. Migrate OTP System to HMAC Signed Tokens (Stateless / No DB Storage)

`portal/lib.php` currently writes every issued OTP to the `applicant_otps` database table.

- [x] `otp_signature()` (`portal/lib.php:199`) is the HMAC; `issue_otp()` keeps only
      `email`, `expires`, `signature` and `attempts` in the session. Nothing about a code is
      written down anywhere.
- [x] `verify_otp()` recomputes it from the typed code and compares with `hash_equals()`, then
      looks the role up — the code is proved first and is still not a licence to be a dealer,
      so an address switched off since the code went out opens nothing.
- [x] Rate limiting kept, and made stronger: `otp_recent_count()` counts per address **and**
      per IP in `login_attempts` under an `otp:` prefix. A session store on its own would have
      made the cap a formality — clear a cookie, ask again.
- [x] No writes to `applicant_otps` anywhere. The table is left in `schema.sql`; it can be
      dropped once nothing on the production database needs reading back.

**The key:** `app_secret()` (`admin/config.php:45`) signs both this and the unsubscribe link.
It is deliberately not a constant in the tracked file — define `APP_SECRET` in
`config.local.php` to set it by hand, or one is made on first use and kept at
`admin/logs/app-secret.key`, in a directory the web server already denies and `admin/logs/.gitignore`
already covers. Changing it invalidates every live code, which for a ten-minute code costs
nothing.

**Check:** the signature is covered by `admin/tests/section-e.php` — the same inputs match, a
changed code, address or expiry does not, and the code cannot be read back out of it. The
end-to-end sign-in was **not** driven here: no code can be delivered to this machine to type
back. Worth doing once on the production host.

---

## F. Decisions, not fixes

- [ ] **F1. Should a blog post carry links and emphasis? · §10.3** It cannot today —
      `<b>bold</b>` prints as characters. That is the price of the (correct) XSS defence. If
      formatting is wanted, the safe route is a small allow-list, or Markdown rendered
      server-side, not trusting the field.
- [ ] **F2. Should the raffle draw be performed rather than typed in? · §10.4** Winners are
      entered by hand from the search box; `raffle_draws.drawn_at` and `drawn_by` are never
      written. For a public promotion offering gold, a recorded timestamped draw from a
      stated pool is a stronger story.
- [ ] **F2a. Build the raffle payout, or take the columns out. · §11.3** Recording _that_
      somebody won is built. Everything after it is schema with no code behind it:
      `raffle_winners.payout_status`, `prize_choice`, `cash_amount`, `paid_at`, `note`,
      `shuffles`. A winner cannot currently choose gold or cash, and nothing can mark a
      prize handed over.
- [ ] **F3. Should `distributor/payouts` say when the next bundle goes out? · §10.1** While
      a bundle is open there is no Bundle button and no copy explaining why. One line
      ("your next bundle goes out on Friday") closes the gap.
- [ ] **F4. Is the cookie bar right as a hard gate? · §5** A full-page scrim blocks the site
      until answered, including on both apply pages, where it stands between a motivated
      visitor and the form. Also consider not autofocusing **Accept** (`main.js:415`) —
      focus the region, or Decline.
- [ ] **F5. Drop the dead columns, or write down why they stay. · §4.12, §10.4**
      `applications.payment_reference`, `payment_proof_path`, `payment_uploaded_at`,
      `payment_rejected_at`, `payment_reject_reason`; `stock_orders.product` and `quantity`;
      `raffle_draws.pool_size`. All superseded, all still readable as authoritative.
      (`booking_paid_at`, `delivery_paid_at` and `payment_verified_at` are **not** dead —
      `raffle_eligible_count()` reads the first.)
- [ ] **F6. Remove the dead `.htaccess` line for `receipt-pdf.php`. · §4.12** It is listed
      both as answered-at-its-own-address and as denied. The deny wins, which is right —
      nothing fetches it. The allow line makes it read as a reachable endpoint.

---

## G. Clean up the QA data · §9

Local database only. A1/A2 above cover the two urgent ones; this is the rest.

```sql
DELETE FROM blog_posts            WHERE id IN (1,2,3);
DELETE FROM newsletter_subscribers WHERE email LIKE 'probe_@example.net';
DELETE FROM contact_messages       WHERE email = 'qa.contact@example.com';
DELETE FROM raffle_winners         WHERE draw_id = 1;
-- vouchers, payouts and their events record money that was never transferred:
DELETE FROM commission_voucher_events WHERE voucher_id IN (34,35,36);
DELETE FROM commission_voucher_lines  WHERE voucher_id IN (34,35,36);
DELETE FROM dealer_payouts            WHERE voucher_id IN (34,35,36);
DELETE FROM distributor_payouts       WHERE voucher_id IN (34,35,36);
DELETE FROM commission_vouchers       WHERE id IN (34,35,36);
-- the two QA applications last, once you have looked at 552's receipt PDFs
-- and at 553's rejected-then-resubmitted payment history:
-- DELETE FROM payments     WHERE application_id IN (552, 553);
-- DELETE FROM applications WHERE id IN (552, 553);
```

- [ ] Also remove `admin/uploads/20260901-1952*` and `20260901-215*` (four files) once
      552 and 553 go.
- [ ] `email_log` rows 86–126 are a record of what was sent. **Read the `ok = 0` rows
      before clearing them** — 121, 122, 124, 125 and 126 are the provider rate limit, and
      they are the evidence behind the AA group above.

**If this database is ever promoted rather than rebuilt, the voucher and payout rows will
read as settled payouts to a real dealer and a real distributor.** Clear them either way.

---

## Still not tested · §8

The four items that stood here have been worked through — §11 of the report. What genuinely
remains:

- [ ] **Open the emails in Outlook, Gmail and Apple Mail.** All twenty were rendered and
      audited for construction and the markup is right — no flex, no grid, no CSS variables,
      no classes, no `<style>` block, every layout a `role="presentation"` table, every image
      with `alt` and `width`. But nothing has been opened in a real client, and the dark-mode
      problem in D7 is inferred from the missing media query rather than observed.
- [ ] **HTTPS behaviour** — HSTS, `Secure` cookies, mixed content. Cannot be tested on a
      plain-HTTP local install (see E9).
- [ ] **Capacity.** §11.4 is 150 requests at 25 concurrent per endpoint with zero failures,
      on a laptop that was also running the browser. It says the application does not fall
      over when requests overlap. It says nothing about the production host.
- [ ] **Steady-state mail delivery.** §4.13 establishes what happens when the provider
      refuses. What the real hourly ceiling is on the production mailbox is unknown, and it
      is the number that decides whether AA1–AA3 are urgent or merely correct.

Done in the third pass, for the record: every email template rendered and audited (§11.1);
payment rejection driven end to end including both empty-reason paths, the proof file
deletion and the applicant's re-upload (§11.2); the raffle switched on, its public reveal
checked and the setting restored (§11.3); and the concurrency run (§11.4). The raffle
_payout_ turned out not to be untested but unbuilt — F2a.
