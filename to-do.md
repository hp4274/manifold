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
- [ ] Move the credentials out of the tracked file:

```php
/* admin/config.php — read the secrets from an untracked file beside it */
$local = __DIR__ . '/config.local.php';
if (is_file($local)) { require $local; }        // defines SMTP_PASS etc.
```

- [ ] `echo "admin/config.local.php" >> .gitignore`
- [ ] `git rm --cached admin/config.php` and commit, or keep the file tracked with the
      secret removed — either way the value must stop being in new commits.
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

- [ ] Change the subject to a constant; the code is already in the body.

```php
return send_mail($to, 'Your Manifold sign-in code',
                 email_wrap('Your sign-in code', $inner), 'otp');
```

**Check:** request a code, then
`SELECT subject FROM email_log WHERE kind='otp' ORDER BY id DESC LIMIT 1;` — no digits.

### A5. Point `PUBLIC_BASE_URL` at production · §3.6

`admin/config.php:91` is now `'http://localhost/manifold'`. That was the fix for the Friday
cron putting `http://localhost.` in every commission email, but it is the **local** address.

- [ ] Set it to the real site address as part of deploying.

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

- [ ] Uncomment `LoadModule deflate_module modules/mod_deflate.so`
- [ ] Uncomment `LoadModule expires_module modules/mod_expires.so`
- [ ] Restart Apache.
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

- [ ] Add intrinsic `width` and `height` to every `<img>` in the ten HTML pages and in the
      PHP partials.
- [ ] Add `loading="lazy"` to everything below the fold. **Not** the hero image — lazy on
      the LCP element makes it slower.
- [ ] While there: `srcset` for the three large home-page images, so a 390 px phone is not
      sent the 1440 px file.

Largest offenders: `stove-2.webp` 187 KB, `sdfgnfdsesdf.webp` 161 KB, `manifold.webp`
118 KB, `faq-tuktuk-portrait.webp` 217 KB.

**Check:** the audit script reports `noDims=0` and `noLazy` only for the hero.

### B3. Settle `.com` vs `.co.in` · §4.3

Every public page and the email footer say `info@manifoldcleanenergy.com`. `MAIL_FROM`,
`MAIL_REPLY_TO`, the SMTP account and the office recipients all say `.co.in`. Mail arrives
from one address while the site tells customers to write to the other.

- [ ] Decide which is correct.
- [ ] `grep -rn "manifoldcleanenergy\.\(com\|co\.in\)" --include=*.php --include=*.html .`
      — 16 files carry `.com`, 6 lines in `admin/config.php` carry `.co.in`.
- [ ] Make `admin/mailer.php:88` stamp the `Message-ID` with the same domain as
      `MAIL_FROM`; a mismatch is a spam-score signal.

### B4. Fix the raffle pool count · §11.3

The popup prints _"3 applicants are in the next draw … and a past winner does not go back
in."_ `raffle_eligible_count()` (`admin/raffle-lib.php:269`) does not exclude past winners,
so the number contradicts the rule beside it. With one winner recorded, the honest figure
is 2.

- [ ] Add the exclusion, the way `raffle_search()` already does twenty lines further up:

```sql
SELECT COUNT(*) FROM applications a
 WHERE a.booking_paid_at IS NOT NULL AND a.status <> 'rejected'
   AND a.id NOT IN (SELECT application_id FROM raffle_winners)
```

- [ ] Or change the sentence. Either is fine; both saying different things is not, on a
      promotion whose pitch is that it is drawn fairly in public.

### B5. Fix the raffle countdown copy · §10.4

With `raffle_enabled = 0`, `admin/raffle` still says _"GOES PUBLIC IN 13d 02h 57m"_. It
will not go public at all until the toggle is on.

- [ ] When the raffle is switched off, say so instead of counting down — e.g. "Switched
      off. Turn it on to start the countdown."

---

## C. The apply form — conversion and clarity

### C1. Stop disabling the submit button · §4.7

`assets/js/main.js:1348`. On a sixty-field form with the consent boxes at the bottom,
someone who misses one presses a dead button. The `title` tooltip needs a hover, so on a
phone there is no feedback at all, and a `disabled` button is off the tab order.

- [ ] Delete `button.disabled = !ready;` and keep the `is-locked` class for the look.
- [ ] Let `apply.js` do what it already does — mark every wrong field red at once, including
      the unticked consent boxes, and raise the toast.

**Check:** on a phone-width viewport, pressing Submit with the boxes unticked scrolls to
them and turns them red.

### C2. Make Approve ask before it emails · §4.8

`admin/partials/row-actions.php:38`. Approving emails a stranger a ₹3,500 payment demand
and opens their portal. Rejecting, which sends nothing, already asks. That is backwards.

- [ ] Add `data-confirm` to the approve form, matching the pattern used everywhere else:

```php
<form method="post" action="status.php"
      data-confirm="Approve <?= e(record_title($rowType, $row)) ?>? They are emailed the
                    payment details and their portal opens.">
```

### C3. Progress, draft saving and an error summary · §5

The form is ten `.form-step` sections and roughly sixty fields on one scroll.

- [ ] Sticky "Step 4 of 10" indicator — the steps already exist as discrete markup.
- [ ] Save answers to `localStorage` on `input`, restore on load, clear on success. Closing
      the tab at field fifty-five currently loses everything.
- [ ] Error summary at the top of the form on a failed submit — "6 fields need attention",
      each an anchor link. Fifteen red boxes spread over several screens with focus on the
      first is not navigable.
- [ ] Make the messages specific. All fifteen currently read `"This one is needed."`;
      `apply.js:73` already has the branches for it.

### C4. Country names on the dial-code selector · §4.9

`apply-stove.html:340` — 170 options reading `+1`, `+7`, `+20`, `+27` … with no country
names, sorted numerically.

- [ ] Label them `India +91`, `Nepal +977` and sort alphabetically by country.
- [ ] Consider making `country` a select like `nationality`, or `nationality` a text input
      like `country` — today the two adjacent fields ask the same kind of question in two
      different ways.

---

## D. Accessibility

### D1. The three contrast tokens · §7.1

Verified ratios, all below the 4.5:1 that WCAG AA asks for normal text:

| Token                      | Now       | Ratio | Replace with                      | New ratio                       |
| -------------------------- | --------- | ----- | --------------------------------- | ------------------------------- |
| `--muted`                  | `#8499ac` | 2.94  | `#5c7389`                         | 4.92 on white, 4.66 on `--tint` |
| `--accent-2` as small text | `#17b0a6` | 2.69  | new `--accent-ink-2: #0c7a74`     | 5.18                            |
| white on `--accent`        | `#4bb453` | 2.64  | white on `--accent-ink` `#2e7d34` | 5.12                            |

- [ ] Change `--muted` in the `:root` block of `assets/css/style.css`.
- [ ] Add `--accent-ink-2` and use it wherever `--accent-2` sets text at `--t-micro` (11px)
      — the eyebrow labels "Overview", "Reach us", "Sign in", "Application". Leave
      `--accent-2` alone as a fill, a border and for large headings.
- [ ] Use `--accent-ink` as the background for the Subscribe button and the green badges.
- [ ] Update `.claude/theme.md` to match — CLAUDE.md asks for the tokens to stay in sync.
- [ ] **Look at the result.** This touches all thirteen sections of the stylesheet.

### D2. Skip link · §5

No page has one, and the header is a topbar plus a full navigation, so a keyboard user
passes about a dozen links before reaching content on every page.

- [ ] Add as the first element in `<body>` on all ten public pages and the portal partials:

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

- [ ] Give the `<main>` element `id="main"` (there is exactly one per page already).

### D3. Heading levels · §5

`h1 → h3` and `h2 → h4` on `/`, `/stove`, `/tuktuk`; `h1 → h4` on `/blog` and
`/coming-soon`; `h2 → h4` on both apply pages.

- [ ] Renumber so no level is skipped. Size comes from CSS, not from the tag.

### D4. Focus ring on the `TukTuk` nav link · §5

No outline and no box-shadow on focus on `/stove`, `/apply-stove`, `/contact`, `/portal/`.
Every other control tested has one, so this looks like a missing selector.

- [ ] Find the rule that covers the other nav links and include this one.

### D5. Touch targets · §5

Footer social icons 26×26 px, the email link 32×32, "See the prices" 120×32.

- [ ] Pad to at least 44×44 px. Padding, not font size — the design does not need to change.

### D6. Raffle popup: `role` and `aria-modal` · §11.3

It moves focus to its close button, which is right, but carries no `role="dialog"` and no
`aria-modal`. The admin's own confirmation dialog (`admin/assets/admin.js:64`) sets
`role="alertdialog" aria-modal="true"`.

- [ ] Match it.

### D7. `lang`, preheader and dark mode in the email wrapper · §4.15

All twenty templates lack all three. One change in `email_wrap()` covers every one.

- [ ] `<html lang="en">` — currently no template sets it.
- [ ] A preheader: a hidden line at the very top of the body that becomes the inbox preview.
      Every template currently opens with the masthead table, so the preview shows the logo
      block instead of a sentence chosen for it.

```html
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
  <?= e($preheader) ?>
</div>
```

- [ ] A `prefers-color-scheme: dark` block setting an explicit background and colour. Gmail
      and Apple Mail auto-invert a white card, and the navy `#0f2c4d` body text is exactly
      the colour that comes out badly.

---

## E. Data integrity and abuse

### E1. Record the commission rate · §10.1

`commission_lines.rate` is `0.00` on every row while `amount` is correct. `admin/submit.php`
freezes the price and the split onto the sale so _"raising a rate later must not rewrite
what this sale was worth"_ — but the rate that produced the amount is the one part not
written down.

- [ ] Populate `rate` wherever the line is inserted. The column already exists.

### E2. Rate-limit the public form endpoint · §4.5

Six newsletter posts back to back were all accepted. Each accepted application, contact
message and newsletter signup sends mail, so this is both database spam and a way to burn
the sending reputation of a shared mailbox.

- [ ] Throttle `admin/submit.php` by IP. The pattern already exists — admin login uses
      `login_attempts` (8 per 15 minutes) and OTP issue uses `OTP_MAX_PER_HOUR`.
- [ ] Five posts an hour from one address is a reasonable starting point.

**Check:** the sixth rapid post is refused.

### E3. Make the portal sign-in reply uniform · §4.6

`portal/lib.php:228`. The form distinguishes three states for any address typed at it —
unknown, application pending, active customer — which with no per-IP throttle is a workable
customer-list harvester.

- [ ] Always answer _"If that address is registered with us, a six-digit code is on its
      way."_
- [ ] Move the "your application is still with our team" explanation into the email that
      address receives, so the kindness survives without the leak.

### E4. Check the stock-order reference · §10.2

`stock_orders.reference` ties a stock order to a bank transaction and accepts anything —
the two existing rows read `34t5yuio9` and `tcvgbhjkl,;.'/`.

- [ ] Add a minimum length, or a light format check, in `distributor/stock.php`.

### E5. Newsletter unsubscribe header · §4.11

`admin/mailer.php` sets no `List-Unsubscribe`. Gmail and Yahoo both require one-click
unsubscribe on bulk mail; without it the newsletter starts landing in spam and drags
transactional mail from the same domain with it.

- [ ] Add `List-Unsubscribe` and `List-Unsubscribe-Post` headers to newsletter sends, and
      an unsubscribe endpoint behind a signed token.

### E5i. Guard the zero-amount emails · §11.1

Rendering the templates produced _"Reminder: the booking payment of ₹0.00 is due"_ and
_"Your referral reward of ₹0.00 is on its way"_. Neither is reachable from the interface —
the reminder bell only renders at `booking_pending` or `delivery_pending` — but
`payment.php?action=remind` is reachable directly.

- [ ] Refuse to send either when the amount is zero.

### E5ii. Speed up `/portal/` · §11.4

Three times slower than any other page under load: p50 231 ms against 93 ms for the home
page, p99 846 ms. It starts a session and calls `portal_roles()`, which queries
`applications`, `dealers` and `distributors` before deciding anything. It is also the page a
customer arrives at from a payment email.

- [ ] Collapse the three role queries into one, or cache the role in the session.

### E6. Contact form: submit the way the apply form does · §4.10

The contact form does a full page POST and returns to `/contact?error=1` with an **empty
form** and a generic toast. `admin/submit.php` already answers JSON when asked.

- [ ] Point the contact form at the same `fetch` path `apply.js` uses, so a rejected
      submission keeps what was typed and can say which field was wrong.

### E7. Harden the admin login throttle · §4.12

The throttle counts attempts per email address only, so spraying one common password across
many addresses is not slowed at all.

- [ ] Add an IP dimension to the `login_attempts` query in `admin/login.php:24`.

### E8. Server banner · §4.12

`Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12`.

- [ ] `ServerTokens Prod` and `ServerSignature Off` in `httpd.conf`.

### E9. HTTPS, in production · §8

Could not be tested locally.

- [ ] Add `Secure` to the session cookie (it is already `HttpOnly; SameSite=Lax`).
- [ ] Add HSTS on the HTTPS host — deliberately left out of `.htaccess` so it cannot lock
      a local install out of `http://`.
- [ ] Check for mixed content once the site is behind TLS.

### E10. Migrate OTP System to HMAC Signed Tokens (Stateless / No DB Storage)

`portal/lib.php` currently writes every issued OTP to the `applicant_otps` database table.

- [ ] Update `issue_otp()` in `portal/lib.php` to issue stateless tokens:
      Generate signature `hash_hmac('sha256', $email . $expires_at . $code, SECRET_KEY)`.
      Pass `$expires_at` and `signature` via session or encrypted cookie.
- [ ] Update `verify_otp()` to re-calculate `hash_hmac('sha256', $email . $expires_at . $user_input_code, SECRET_KEY)` and compare with the signature using `hash_equals()`.
- [ ] Retain rate limiting per IP/email using `login_attempts` or local session store.
- [ ] Deprecate dynamic table writes to `applicant_otps`.

**Check:** Requesting and verifying an OTP logs the user in correctly without creating rows in `applicant_otps`.

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
