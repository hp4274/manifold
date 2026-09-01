# QA, UI/UX and performance report — Manifold Clean Energy

**Date:** 1 September 2026
**Build:** `main` @ `0164c0a` + uncommitted working tree
**Environment:** XAMPP, Apache 2.4.58, PHP 8.2.12, MariaDB, `http://localhost/manifold`
**Method:** Playwright (Chromium 1.62) driving real browser sessions at 390 / 768 / 1440 px,
plus `curl` probes and direct database verification of every state transition.

---

## 1. Verdict

The application is in good shape. Every business flow tested completed end to end and
produced correct database state, correct emails and correct PDFs. Across roughly 60 page
loads under three viewports there were **zero JavaScript errors, zero HTTP 4xx/5xx on
legitimate routes, zero horizontal overflow, and zero new PHP warnings**. Input validation
is duplicated server-side rather than trusted from the browser, CSRF is enforced, and
cross-tenant access is properly refused.

The problems that exist are concentrated in three places: **one form bug that silently
disabled client-side validation**, **web server configuration** (the repository was
publicly readable and nothing was compressed or cached), and **colour contrast**.

A second pass covered the four modules the first pass had left alone — the commission
voucher chain, stock ordering, the blog and the raffle. All four are correct. The money
chain in particular was followed through seven hand-offs and two ledgers and reconciles to
the rupee. That pass is §10. It found one real defect — a payment could be refused without
a reason, which is now fixed (§3.5) — and a handful of smaller things. For the most
intricate part of the system that is a good result.

A third pass closed out what had been deferred: every email template rendered and audited,
a payment rejection driven end to end, the raffle switched on and its public reveal
checked, and a concurrency run. That pass is §11, and it is where the two most consequential
findings in this report came from — both about email, and both invisible until enough mail
was actually sent. Every link in a commission email sent by the Friday cron pointed at
`http://localhost.` (§3.6), and the mailbox hit its provider's hourly rate limit during
testing while the portal went on telling people a sign-in code was on its way (§3.7). Both
are fixed.

| Area | Result |
|---|---|
| Public site (10 pages × 3 viewports) | Pass — no console errors, no failed requests, no overflow |
| Application form, submit to database | Pass — after the fix in §3.1 |
| Applicant lifecycle (7 stages) | Pass — all transitions, emails and receipts correct |
| Client portal (OTP, upload, receipts) | Pass |
| Admin (16 screens) | Pass |
| Dealer portal (6 screens) | Pass |
| Distributor portal (8 screens) | Pass |
| Contact form / newsletter | Pass (functionally) |
| Access control / CSRF / traversal | Pass |
| Commission voucher chain (cron → dealer → distributor → R&F → office → paid) | Pass — §10.1 |
| Stock ordering and the stock ledger | Pass — §10.2 |
| Blog admin and the public feed | Pass, with two notes — §10.3 |
| Raffle (search, winners, eligibility) | Pass, with three notes — §10.4 |
| Email templates, all 20 rendered and audited | Pass on construction, three gaps — §11.1 |
| Payment rejection, end to end | Pass — §11.2 |
| Raffle public reveal, promotion switched on | Pass, one wrong number — §11.3 |
| Load, 25 concurrent × 8 endpoints | Pass — no failures — §11.4 |
| Payment rejection | **Fail** — no reason required; fixed in §3.5 |
| Injection / XSS / traversal / abuse probes | Pass — §10.5 |
| Server configuration | **Fail** — see §4.1, §6 |
| Colour contrast (WCAG AA) | **Fail** — see §7.1 |

---

## 2. What was exercised

**Full applicant lifecycle, end to end, in a real browser** — application submitted from
`/apply-stove` with two file uploads → office approves → payment email → portal sign-in by
one-time code → booking receipt uploaded → office verifies → receipt PDF issued and emailed
→ documents verified → applicant chooses to go ahead → delivery payment uploaded → office
verifies → order complete.

Database after that run, verified directly:

```
applications.status            complete
payments  booking   verified   MF-00000552-R1
payments  delivery  verified   MF-00000552-R2
```

Fourteen emails were generated across the run, all logged `ok=1`, in the correct order,
with the correct amounts and rupee symbols intact (checked as raw hex — `E282B9`).
The two receipt PDFs render and download (`200 application/pdf`, 39 KB).

Also exercised: dealer sign-in and all six dealer screens; distributor sign-in and all
eight distributor screens; cross-tenant attempts from a dealer session; sixteen admin
screens including the record drawer, the payment panel and the confirmation dialogs;
contact form submission through to `contact_messages`; newsletter signup; unauthenticated
access to every protected route; path traversal against the file server.

---

## 3. Fixed during this session

### 3.1 The ID-number field accepted anything (both apply forms) — **was a live bug**

`apply-stove.html`, `apply-tuktuk.html`

```html
pattern="[A-Za-z0-9\-/]{4,20}"      <!-- before -->
pattern="[A-Za-z0-9\-\/]{4,20}"     <!-- after  -->
```

Chromium compiles the HTML `pattern` attribute with the RegExp `v` flag, in which a bare
`/` inside a character class is a syntax error. The whole pattern was therefore discarded
and the browser logged, on every keystroke:

> `Pattern attribute value [A-Za-z0-9\-/]{4,20} is not a valid regular expression: … Invalid character in character class`

Measured before the fix: `!!!` and `AB` both passed `checkValidity()` on the ID field.
Measured after: both correctly fail, and the console error is gone.

The practical cost was the worst kind — the server *did* validate correctly, so an
applicant who mistyped their Aadhaar or passport number filled in the whole 60-field form,
pressed Submit, and got a red toast at the bottom of the page instead of a red border on
the field, with no indication of which field was wrong.

### 3.2 The Git repository was readable over the web — **critical**

`.htaccess`

```
$ curl http://localhost/manifold/.git/config
[remote "origin"]
        url = https://github.com/hp4274/manifold.git
$ curl -o /dev/null -w '%{http_code}' .../.git/index   →  200  (17 KB)
```

`.git/config`, `.git/HEAD` and `.git/index` were all served. That is enough for a standard
dumped-repository attack to reconstruct the entire source history — including
`admin/config.php`, which carries the live SMTP host, username and password in plain text.

Added `Options -Indexes`, a 404 for anything under `.git`, a deny for dotfiles, and a 404
for `graphify-out/`, `.playwright-cli/`, `.reticle/` and `admin/tests/` — the last four
were serving directory listings (the Playwright log directory alone listed 45 KB of files).

Verified after: `.git/HEAD` → 404, `graphify-out/` → 404, and all fourteen real routes
still 200.

> **This does not undo the exposure.** The SMTP password in `admin/config.php` has been
> reachable and is in Git history. Treat it as compromised: rotate it in the Hostinger
> panel, move the credentials to environment variables or an untracked
> `config.local.php`, and `git rm --cached admin/config.php`.

### 3.3 A blurred payment form was still reachable by keyboard

`portal/status.php`

When a booking is verified but finance has not yet checked documents, the delivery-payment
block is blurred and given `aria-hidden="true"`. `pointer-events:none` stops the mouse, but
not the Tab key. Measured on a real session:

```
sealed section: { inert: false, ariaHidden: "true",
  keyboardReachable: ["csrf","id","stage","payment_reference","payment_proof",
                      "Upload delivery payment proof"] }
```

Focusable controls inside an `aria-hidden` container is the one combination ARIA
explicitly forbids — a keyboard user lands on a file input that announces nothing and that
the server will reject anyway. Added `inert` alongside the existing `aria-hidden`, which
removes both the tab stop and the accessibility tree entry in one attribute. No visual
change.

### 3.4 Caching and compression rules added (inert until two Apache modules are enabled)

`.htaccess` now sets far-future `Cache-Control` on fingerprinted assets, `no-cache` on
HTML, and `DEFLATE` on all text types, plus `X-Content-Type-Options`, `Referrer-Policy`,
`X-Frame-Options` and `Permissions-Policy`. The headers took effect immediately. The
compression block did not — see §6.1.

### 3.5 A payment could be refused without a reason — found in the second pass

`admin/payment.php`, `admin/partials/payment-panel.php`

The reject form's `reason` box was not `required`, and `admin/payment.php` wrote
`reject_reason = NULL` for an empty one before emailing the applicant. The result would have
been a message telling somebody their ₹3,500 receipt was refused, with no reason in it and
nothing downstream to fill the gap.

Added `required` to the input, and a server-side refusal (HTTP 422) ahead of the update, the
proof deletion and the email — so an empty reason cannot get through by a hand-made request
either. The order matters: nothing is written and no file is deleted before the check.

### 3.6 Every link in a cron-sent email pointed at `http://localhost.` — **was a live bug**

`admin/config.php:91`

`PUBLIC_BASE_URL` was `''`, so `base_url()` fell through to working the address out of the
current request. The Friday voucher run has no request:

```
$ php -r "require 'admin/config.php'; echo base_url();"
http://localhost.
```

— a trailing dot, because `dirname()` of the CLI script name is `.`. `voucher_notify()`
builds every link in a commission email from `base_url()`, and the cron calls it. The four
`voucher_update` mails the run sends — to a dealer, a distributor, R&F and the office,
telling each of them money has moved — all carried a dead link.

Set `PUBLIC_BASE_URL` to the site address, with a note to change it on deployment. That
also takes the address in a live email out of the hands of whatever `Host` header the
request arrived with.

### 3.7 A failed sign-in code was reported as sent — **was a live bug**

`portal/lib.php`, `issue_otp()`

Sending enough mail to exercise the templates hit the provider's cap, and the log is
unambiguous:

```
451 4.7.1 Ratelimit "hostinger_out_ratelimit" exceeded for key "RLaobdhmgar3my6dgxbnqkfqpg"
454 4.3.0 Try again later
```

Three of six sends failed in one window. `issue_otp()` called `send_otp_email()` and
discarded the result, then returned `''` — success — so the portal answered *"A six-digit
code is on its way to … It is valid for 10 minutes."* for a code that was never sent. The
person waits, the code expires, and nothing anywhere says otherwise.

Now the failure is passed back and said plainly, and the unused code is deleted with it —
otherwise a send this system failed to make would still count against that address's six
codes an hour and lock them out over our own fault.

This fixes the message. It does not fix the delivery — see §4.13.

---

## 4. Findings not fixed

### 4.1 Server: no compression at all — **high, biggest single performance win**

```
$ curl -H 'Accept-Encoding: gzip' -D - .../assets/css/style.css | grep -i content-
Content-Length: 151238          ← no Content-Encoding header
```

`mod_deflate` and `mod_expires` are commented out in `C:\xampp\apache\conf\httpd.conf`:

```
#LoadModule deflate_module modules/mod_deflate.so
#LoadModule expires_module modules/mod_expires.so
```

Uncomment both and restart Apache; the rules are already written and waiting. Expected
effect on the home page, which currently transfers **1,394 KB**:

| Asset | Now | After gzip (approx.) |
|---|---|---|
| `bootstrap.min.css` | 228 KB | ~30 KB |
| `style.css` | 148 KB | ~25 KB |
| `index.html` | 50 KB | ~10 KB |
| **Text subtotal** | **426 KB** | **~65 KB** |

Roughly **360 KB saved on first paint**, before touching a single image. On a 3G
connection in a tier-2 Indian city — which is the actual audience for a ₹35,000 stove —
that is several seconds.

Make sure the same two modules are enabled on the production host, not only locally.

### 4.2 The one-time sign-in code is in the email subject line — **high**

```
subject: Your Manifold sign-in code: 205598
```

`admin/emails.php:775`. A subject line appears in lock-screen notifications, in the mail
client's message list, in shoulder-view on a shared laptop, and is retained by more
intermediaries than the body. A phone left on a desk shows the code to anyone walking past.

Change the subject to a constant — `Your Manifold sign-in code` — and leave the six digits
in the body, where they already are, rendered large.

Note this also means the codes are stored in clear text in the `email_log.subject` column
for anyone with database read access. Fixing the subject fixes that too.

### 4.3 Two different company email addresses — **high, customer-facing**

| Where | Address |
|---|---|
| Every public page, and the footer of every email | `info@manifoldcleanenergy.com` |
| `MAIL_FROM`, `MAIL_REPLY_TO`, SMTP account, office recipients | `info@manifoldcleanenergy.co.in` |

Mail therefore arrives *from* `.co.in`, while the page the customer read tells them to
write to `.com`. Unless both mailboxes exist and are watched, replies are being lost. This
is a decision only you can make — pick one and make it consistent. Sixteen files carry the
`.com` form; six lines in `admin/config.php` carry `.co.in`.

While there: `admin/mailer.php:88` stamps `Message-ID: <…@manifoldcleanenergy.com>` while
the envelope sender is `.co.in`. A Message-ID domain that does not match the sending domain
is a small but real spam-score signal. Make it match `MAIL_FROM`.

### 4.4 Default credentials are documented and still active — **high before launch**

`admin/README.md:16` and `CLIENT-FLOW.md:716` publish `admin` / `admin12345` and
`rf@manifold.com` / `rf123`. Both still work — that is how this test session signed in.
Both READMEs say to change them; neither has been changed. Change them before the site is
reachable from the internet, and delete the passwords from the documentation.

### 4.5 No rate limit on the public form endpoint — **medium**

Six newsletter signups posted back to back were all accepted:

```
{"ok":true,"message":"You are on the list."}   ×6, no delay, no throttle
```

`admin/submit.php` has a honeypot (`website`) and nothing else. Every accepted application,
contact message and newsletter signup triggers an outbound SMTP send, so this is both a
database-spam vector and a way to burn the sending reputation of a shared Hostinger mailbox.

Admin login is throttled properly (8 attempts / 15 min) and OTP issue is throttled per
address, so the pattern already exists in the codebase. The lazy fix is to reuse
`login_attempts` — or one small table — keyed on IP, and refuse more than, say, five posts
an hour from one address.

### 4.6 The portal tells an attacker which addresses are customers — **medium**

```
Input: definitely-not-a-customer@example.org
Reply: "We do not recognise that email address."
```

`portal/lib.php:228`. There is a second variant that reveals more: an address with an
application still awaiting approval is told *"Your application is with our team."* So the
sign-in form distinguishes three states — unknown, application pending, and active
customer — for any address an attacker cares to type. Combined with no per-IP throttle
(§4.5) that is a workable customer-list harvester.

The code comments show this was a deliberate kindness, and it is a genuine trade-off. A
middle path keeps the kindness without the leak: always answer *"If that address is
registered with us, a six-digit code is on its way"*, and put the "your application is
still with our team" explanation in the email that address receives.

### 4.7 The submit button is disabled until the consent boxes are ticked — **medium UX**

`assets/js/main.js:1348`

```html
<button disabled title="Accept the required terms to continue" class="… is-locked">
```

Measured state on page load: `{ disabled: true, ariaDisabled: null, focusable: 0 }`.

On a form with roughly sixty fields and consent checkboxes at the very bottom, someone who
fills everything in and misses a checkbox presses a button that does nothing and says
nothing. The `title` tooltip needs a hover, so on every phone — the majority of this
audience — there is no feedback at all. A `disabled` button is also removed from the tab
order, so a screen-reader user cannot find it to be told why.

The form already has the right machinery: `apply.js` turns every invalid field red at once
and raises a toast. Let it do its job — keep the button enabled, and let a click on an
unticked form mark the consent boxes red like any other required field. The change is to
delete `button.disabled = !ready` and keep the `is-locked` class for the visual state.

### 4.8 Approving an application does not ask; rejecting does — **medium UX**

`admin/partials/row-actions.php:38` — the Approve button carries
`title="Approve — sends the payment email"` and submits on a single click. The Reject
button beside it, which sends nothing, opens a confirmation dialog.

That is backwards. Approving emails a stranger a demand for ₹3,500 with a payment QR code
and opens their portal. It is the outward-facing, hard-to-retract action of the two, and it
sits one mis-click away in a dense table row next to a Reject button of the same size. Give
it the same `data-confirm` the rest of the panel already uses.

### 4.9 The country-code selector shows only numbers — **medium UX**

`apply-stove.html:340` — 170 options reading `+1`, `+7`, `+20`, `+27`, `+30`, `+31` …
with no country names, sorted numerically.

The default (`+91`, following the Indian nationality default) is correct, so most people
never touch it. But anyone who does — a Nepali or Bangladeshi applicant, or someone who
opens it by accident — is scrolling 170 bare numbers with nothing to recognise. Label them
`India +91`, `Nepal +977` and sort alphabetically by country.

Related: `nationality` is a 172-option `<select>` while `country` two fields later is a free
text input. Two fields asking essentially the same kind of question in two different ways.

### 4.10 The contact form and the apply form behave differently on submit — **low UX**

The apply form posts by `fetch` and replaces itself with an inline success panel carrying
the booking number. The contact form does a full page POST and comes back as
`/contact?sent=1`, where `main.js` raises a toast.

Two consequences on the contact form: the page scroll position is lost, and on `?error=1`
the visitor returns to an **empty form** with a generic red toast and no idea which field
was wrong. `admin/submit.php` already answers JSON when asked. Pointing the contact form at
the same `fetch` path the apply form uses would make both consistent and stop discarding
what somebody typed.

### 4.11 No `List-Unsubscribe` header on newsletter mail — **low**

`admin/mailer.php` sets `Date`, `From`, `Reply-To`, `To`, `Subject`, `MIME-Version`,
`Message-ID`, `X-Mailer` — no `List-Unsubscribe`. Gmail and Yahoo both now require
one-click unsubscribe on bulk mail; without it the newsletter will start landing in spam
and drag transactional mail from the same domain down with it.

### 4.12 Small things

- `admin/receipt-pdf.php` is listed in the `.htaccess` "answered at their own address"
  block *and* in the `Require all denied` block. The deny wins (403), which is correct —
  nothing fetches it, it is only ever `require_once`'d. The allow line is dead; remove it
  so the file does not read as a reachable endpoint.
- The consent bar focuses the **Accept** button on open (`main.js:415`). The two buttons are
  otherwise even-handed; focusing the more privacy-invasive one is the kind of nudge a
  regulator reads as a dark pattern. Focus the bar's region, or Decline.
- `applications` still carries `payment_reference`, `payment_proof_path`,
  `payment_uploaded_at`, `payment_rejected_at`, `payment_reject_reason`. Per-payment data
  moved to the `payments` table; these five stayed `NULL` through the entire lifecycle run.
  (`booking_paid_at`, `delivery_paid_at` and `payment_verified_at` *are* still written and
  are load-bearing — `raffle_eligible_count()` reads `booking_paid_at`.) The other five are
  dead columns that will eventually mislead somebody. `stock_orders.product` and
  `stock_orders.quantity` are dead in the same way, replaced by `stock_order_items`.
- The admin login throttle counts attempts per email address only. Spraying one common
  password across many addresses is not slowed at all. Add an IP dimension to the same
  `login_attempts` query.
- The `Server` header advertises `Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12`. Set
  `ServerTokens Prod` and `ServerSignature Off` in `httpd.conf`.

### 4.13 Mail that fails is lost silently — **high**

Uncovered by §3.7 and larger than the message it produced. `send_mail()` catches the
throwable, writes `email_log.ok = 0` with the SMTP reply, and returns `false`. There is no
retry, no queue, and nothing that reads `email_log` back:

```
$ grep -rn "email_log" --include=*.php . | grep -v "INSERT INTO email_log"
admin/partials/mail-flash.php:20:  Check the `email_log` table for the reason.
```

That flash is the whole of it, it only fires on an admin-triggered send, and its advice is
to open the database by hand. Everything triggered by an applicant — the application
acknowledgement, the payment email with the QR code and the amount, the sign-in code —
fails without anyone being told.

The lifecycle sends five to eight emails per applicant plus one per sign-in, from a single
shared Hostinger mailbox that has an hourly cap this QA session reached on its own. At real
volume it will be reached daily, and the mail that goes missing will be the payment email.

Three things, in order of value:

1. **A retry.** Write the failed message to a table and have a cron re-send it a few times
   with a widening gap. `4xx` SMTP replies are explicitly temporary — "try again later" is
   what the server actually said.
2. **A view of it.** A "mail that did not go" list in the admin, reading `email_log` where
   `ok = 0`, so the office can see it without a database client.
3. **Headroom.** A transactional sender (SES, Postmark, Brevo) rather than a mailbox meant
   for a person to type into. This is the real fix; 1 and 2 are what makes the failure
   visible in the meantime.

### 4.14 The payment email's only instrument is an image — **medium**

§11.1. The payment email embeds the QR code as an inline image and offers *"Not showing?
Open the QR code on our website."* as its fallback. There is no UPI id, no account number
and no IFSC anywhere in the text.

Two ways that fails. Outlook desktop and Gmail both block images from an unknown sender by
default, so the first thing a new customer sees is a message about paying ₹3,500 with no
visible way to pay. And **a customer reading the email on their phone cannot scan a QR code
displayed on that same phone** — they need a second device, or to save the image and use
their bank app's scan-from-gallery.

Put the UPI id beside the QR as selectable text, and a `upi://pay?pa=…&am=3500` link under
it. One line, works with images off, works on the phone the email is being read on.

### 4.15 Email templates: three gaps that are the same size as the fix — **low**

All twenty templates, audited in §11.1:

- **No `lang` attribute** on any `<html>`. A screen reader in a mail client guesses.
- **No `prefers-color-scheme` handling** on any of them. Gmail and Apple Mail auto-invert a
  white card in dark mode, and the navy `#0f2c4d` body text is exactly the colour that
  comes out badly. Two `@media` blocks with explicit `background` and `color` fixes it.
- **No preheader.** Every one of them begins with the masthead table, so the inbox preview
  line — the second thing after the subject that decides whether a mail is opened — shows
  whatever the logo block yields rather than a sentence chosen for it.
---

## 5. UI and UX observations

**What is working, and worth keeping.** The confirmation dialog (`admin.js:64`) sets its
message as `textContent` rather than markup, focuses the safe answer, traps Tab, closes on
Escape and returns focus to the opener — that is a correctly built modal, which is rarer
than it should be. The consent bar does the same and adds a genuinely thoughtful fallback:
if an ad blocker hides the bar, a 600 ms check notices the missing box and opens the gate
rather than leaving the page behind a scrim nobody can dismiss. Every icon-only button in
the admin carries a `visually-hidden` label. The status timeline in the portal names every
future stage rather than only the current one, so nobody has to guess what happens next.
Empty states are handled — `/distributor/dealers` renders a real empty state, not a bare
table. Tables sit in `overflow-x:auto` wrappers, and there was **no horizontal page
overflow anywhere at 390 px**, admin included.

**Where the interface asks too much.**

*The application form has no progress indication.* Ten `.form-step` sections, roughly sixty
fields, one long scroll. There is no step counter, no progress bar, and no draft saving —
close the tab at field fifty-five and everything is gone. For a ₹35,000 purchase decision
this is the highest-leverage UX change available. The steps already exist as discrete
markup; giving them a sticky "Step 4 of 10" and writing the values to `localStorage` on
`input` would be a contained change.

*Failed validation gives no summary.* Submitting the empty form produced fifteen red field
boxes spread down a page several screens long, one toast at the bottom, and focus moved to
the first bad field. Someone who scrolls away from that field has no way back to the list
of problems. An error summary at the top of the form — "6 fields need attention", each an
anchor link — is the standard pattern and costs very little.

*Every error message is the same sentence.* All fifteen said `"This one is needed."` The
copy elsewhere on this site is unusually good — specific, plain, human. `apply.js:73` has
the branches to be specific here too; it just falls back to one string for `valueMissing`.
"We need your date of birth" reads as though a person wrote it, which is the register the
rest of the site keeps.

*Heading levels skip.* `h1 → h3` and `h2 → h4` on the home page, `/stove` and `/tuktuk`;
`h1 → h4` on `/blog` and `/coming-soon`; `h2 → h4` on both apply pages. A screen-reader
user navigating by heading hears sections that appear to be nested inside sections that do
not exist.

*No skip link on any page.* Confirmed across all ten public pages. The header is a topbar
plus a full navigation, so a keyboard user passes roughly a dozen links before reaching
content — on every page, every time. One anchor and a `:focus` rule fixes it site-wide.

*Touch targets below 44 px on mobile.* The footer social icons measure 26 × 26 px, the
email link 32 × 32, and "See the prices" 120 × 32. WCAG 2.5.8 asks for 24 px minimum and
both platform guidelines ask for 44. Padding, not size, is what is missing.

*One nav link has no focus ring.* The `TukTuk` link in the header showed no outline and no
box-shadow on focus on `/stove`, `/apply-stove`, `/contact` and `/portal/`. Every other
control tested had a visible ring, so this looks like a single selector missing rather than
a design decision.

*The cookie bar is a hard gate.* A full-page scrim blocks the site until the visitor
answers, including on the two apply pages. It is implemented carefully and it is a
defensible reading of consent law, but it is a measurable cost at the top of the funnel —
and on `/apply-stove` it stands between an already-motivated visitor and the form. Worth
considering a non-blocking bar for pages that set no non-essential cookies.

---

## 6. Performance

Measured with the Navigation and Resource Timing APIs, cold cache, three viewports,
localhost (so network latency is nil and these numbers are the floor, not the ceiling).

| Page | Load | DOMContentLoaded | Requests | Transferred |
|---|---|---|---|---|
| `/` | 1.8–3.0 s | 610 ms | 25 | **1,394 KB** |
| `/tuktuk` | 1.7–1.9 s | 516 ms | 22 | 225 KB |
| `/stove` | 1.7–1.9 s | 458 ms | 22 | 131 KB |
| `/apply-stove` | 1.7–1.8 s | 468 ms | 20 | 30 KB + 65 KB doc |
| `/contact` | 2.4–3.0 s | 311 ms | 16 | 36 KB |

Server-side is not the problem. `admin/submit.php` answered a full application with two
file uploads in **255 ms**. Admin screens render in 600–1,500 ms including all queries.
Emails are dispatched after the response is flushed, so nothing waits on SMTP.

### 6.1 Enable `mod_deflate`

The single biggest win, and it is a configuration line rather than a code change. See
§4.1 — roughly 360 KB off the home page.

### 6.2 The home page carries 1.4 MB, most of it images

`stove-2.webp` 187 KB, `sdfgnfdsesdf.webp` 161 KB, `manifold.webp` 118 KB. All already
WebP, so the remaining wins are `srcset` with narrower variants for the 390 px viewport
(currently the same file is sent to a phone as to a 1440 px desktop), and `loading="lazy"`
— **not one image on the site has it**, so every below-the-fold image competes with the
hero for bandwidth.

### 6.3 No image declares `width` and `height`

Ten of eleven on the home page, and all of them on every other page. Without intrinsic
dimensions the browser cannot reserve space, so text reflows as each image arrives. This is
the direct cause of Cumulative Layout Shift, and it is the cheapest Core Web Vitals fix
there is — two attributes per tag.

### 6.4 `bootstrap.min.css` is 228 KB for a handful of components

The custom `style.css` is another 148 KB and does most of the work. Either trim Bootstrap to
the components actually used, or drop it. After gzip this matters much less, so do §4.1
first and re-measure before spending effort here.

### 6.5 `bootstrap-icons.woff2` is 128 KB

For perhaps thirty glyphs. Subsetting to the codepoints actually used takes it under 10 KB.

### 6.6 Caching is now configured, and was absent

No `Cache-Control` and no `Expires` — only `ETag` and `Last-Modified`, so every repeat
visitor was making a conditional request for every asset. §3.4 added the rules; with
`mod_expires` enabled, repeat views should drop to near zero bytes.

---

## 7. Accessibility

Landmarks are correct on every page (one `main`, one `header`, one `footer`). No duplicate
element IDs. No dangling `aria-labelledby` / `aria-describedby` / `aria-controls`
references. Every form control on the public site has a programmatic label. Forty-five
`prefers-reduced-motion` rules — motion is genuinely handled, not an afterthought. No
autoplaying media.

### 7.1 Colour contrast below WCAG AA — the main gap

Three token values fail, and they are used everywhere:

| Token | Value | On | Ratio | Needs | Used for |
|---|---|---|---|---|---|
| `--muted` | `#8499ac` | white | **2.94** | 4.5 | field hints, captions, "Prices are indicative…" |
| `--accent-2` | `#17b0a6` | white | **2.69** | 4.5 | 11 px eyebrow labels — "Overview", "Reach us", "Sign in" |
| `--accent` | `#4bb453` | *as a background* | **2.64** | 4.5 | white text on the Subscribe button and green badges |

`--accent-2` at `--t-micro: 11px` is the worst of the three: the smallest text on the site
in the lowest-contrast colour. `--muted` carries the helper text under the fields of a
sixty-field form — precisely the text somebody needs when they are stuck.

The design system already anticipates this. `:root` defines
`--accent-ink:#2e7d34` with the comment *"the green dark enough to read as small text on
white"*. The same idea, applied to the other two, gives:

```css
--muted:      #5c7389;   /* was #8499ac — 4.92:1 on white, 4.66:1 on --tint */
--accent-2:   #17b0a6;   /* unchanged: fine as a fill, a border, a large heading */
--accent-ink-2: #0c7a74; /* new: --accent-2 for small text — 5.18:1 on white */
```

and for white text on a green fill, use `--accent-ink` (`#2e7d34`, **5.12:1**) as the
background rather than `--accent`.

I have **not** applied these. They touch every one of the thirteen sections in `style.css`
and the result needs a human eye, not a contrast calculator. The numbers above are exact
and verified, so the change is mechanical once you have decided you want it.

### 7.2 Other accessibility items

Already covered above and repeated here so the list is in one place: no skip link on any
page (§5); heading levels skip on seven of ten pages (§5); the `TukTuk` nav link has no
focus ring (§5); touch targets under 44 px on mobile (§5); the disabled submit button is
unreachable by keyboard and unexplained on touch (§4.7). The sealed payment block (§3.3) is
fixed.

---

## 8. Not covered

Stated plainly so the coverage is not overread. The four items that stood here after the
first two passes have since been worked through — see §11. What is left:

- **Real mail clients.** Every template was rendered and audited for construction (§11.1),
  and the markup is right, but nothing here has been opened in Outlook, Gmail or Apple Mail.
  The dark-mode behaviour in §4.15 in particular is a prediction from the absence of the
  media query, not an observation.
- **HTTPS behaviour** — HSTS, `Secure` cookies, mixed content — could not be tested on a
  plain-HTTP local install. The session cookie is `HttpOnly; SameSite=Lax` and needs
  `Secure` adding in production.
- **Capacity.** §11.4 is a concurrency smoke test on a developer machine, not a capacity
  number. Nothing here says what the production host will carry.
- **The raffle payout.** Not untested — **not built**. `raffle_winners.payout_status`,
  `prize_choice`, `cash_amount`, `paid_at`, `note` and `shuffles`, and
  `raffle_draws.drawn_at` and `drawn_by`, are written and read by no code at all (§11.3).
- **Mail actually arriving under load.** §4.13 describes what happens when the provider
  refuses; what the real steady-state delivery rate is on the production mailbox is
  unknown.

## 9. Test data left behind

Everything both passes created, on the local database only. Nothing was deleted — that is
your call. This is the whole list; §10.7 describes the second-pass rows in context.

| Table | Rows | What they are |
|---|---|---|
| `applications` | 552 | `qa.stove.001@example.com` — a full `complete` record with two verified payments |
| `applications` | 553 | `qa.reject.001@example.com` — `booking_review`, used for the rejection run in §11.2 |
| `payments` | 79, 80 | booking + delivery for 552, both `verified`, receipts R1 and R2 |
| `payments` | 81, 82 | 553: one `rejected` (proof deleted from disk), one `pending` |
| `contact_messages` | 1 | `qa.contact@example.com` |
| `newsletter_subscribers` | 1, 3, 4, 5, 6, 7 | `probe1…probe6@example.net`, from the rate-limit probe |
| `email_log` | 86–126 | the mail trail of all three passes. **Rows 121, 122, 124, 125 and 126 are `ok = 0`** — the provider rate limit in §3.7 |
| `commission_vouchers` | 34, 35, 36 | all now `paid` |
| `commission_voucher_lines` | 56, 57, 58 | |
| `commission_voucher_events` | 133–140 | the audit trail of the chain in §10.1 |
| `dealer_payouts` / `distributor_payouts` | one row each | ₹3,000 and ₹4,000 |
| `blog_posts` | 1, 2, 3 | one QA post and **two XSS probe posts, published and live on `/blog`** |
| `raffle_winners` | 1 | application 551 on draw 1 |
| `admin/uploads/` | 4 files | `20260901-1952*` for 552 and `20260901-215*` for 553 |

Two things worth acting on rather than just noting:

- **`blog_posts` 2 and 3 are on the public page now.** The payload does not execute
  (§10.3) but the titles read as garbage to any visitor. Delete 1, 2 and 3.
- The **vouchers, payouts and commission events are real financial rows** for a real dealer
  and distributor. They record money that was never actually transferred. If this database
  is ever promoted rather than rebuilt, they will read as settled payouts.

Application 552 is worth keeping until you have looked at the two receipt PDFs it produced
— it is a complete, correct worked example of the whole flow. 553 sits at `booking_review`
with a rejected payment behind it and a fresh one waiting, which is a useful shape to look
at once before deleting.

Also worth a look before it is cleared: `SELECT * FROM email_log WHERE ok = 0` is the
evidence for §4.13, and there is nothing in the interface that will show it to you.

---

## 10. Second pass — the modules the first pass left alone

Run after the first report was written, against the same build.

### 10.1 The commission voucher chain — **correct, followed end to end**

This is the most intricate logic in the codebase and it holds up. Driven through all seven
hand-offs, in real browser sessions, with the database read between each:

```
1. cron       php admin/cron/voucher-run.php
              → v34  dealer MD4U9NE6  ₹3,000  with_distributor
              → v35  bundle MXLV5ZNN  ₹4,000  with_rf   (distributor's own 2 lines)
2. distributor approves v34                    → bundled, parent NULL
3. cron (next cycle)                           → v36 bundle, v34 parent = 36
4. R&F forwards                                → with_admin
5. office funds                                → funded
6. R&F pays                                    → paid
7. dealer_payouts row 12: dealer 1, ₹3,000, voucher 34
```

Checks that passed:

- **Idempotent.** A second run of the same cycle raised nothing: *"0 dealer vouchers raised,
  0 bundles sent, 2 skipped"*. A doubled schedule or a restarted machine cannot double-pay.
- **Nothing is stranded.** A dealer claim approved *after* its distributor's bundle has
  already left sits at `bundled` with `parent_id` NULL — which looks alarming, but the next
  cycle picks it up and attaches it (`voucher_dealer_claims($id, ['bundled'])`). Confirmed
  by running the next cycle: `v34.parent_id` became 36.
- **A bundle carrying only a dealer's claim still pays the dealer.** `commission_vouchers.amount`
  on that bundle reads `0.00` — it is the *distributor's own* share, not the total — and R&F
  and the office both display the real figure ("Bundle #36 · 1 dealer in it · ₹3,000.00")
  with a per-party breakdown naming the UPI id each share goes to. Paying it wrote
  `dealer_payouts`, not `distributor_payouts`. This is the case most likely to be got wrong
  and it is right.
- **The audit trail is complete and names people, not ids** — six `commission_voucher_events`
  rows reading `the Friday run`, `Harsh Patel`, `R&F`, `the office (admin)`.
- **The dealer's own view reconciles**: "Commission earned ₹3,000.00 · Paid to you ₹3,000.00
  · Still owed to you ₹0.00".

Two notes, neither a defect:

- `commission_lines.rate` is `0.00` on every row while `amount` is correct (₹3,000 / ₹1,000).
  `admin/submit.php` freezes the price and the commission split onto the sale *"for the same
  reason the price is: raising a rate later must not rewrite what this sale was worth"* —
  but the rate that produced the amount is the one part not written down. If a rate ever
  changes there is no record of what this sale was booked at. The column is already there;
  it is just never populated.
- There is no **Bundle** button on `distributor/payouts` while a previous bundle is still
  open, so between approving a dealer's claim and the next Friday the distributor has no way
  to send it on. That is a defensible design, but the page does not say so — it simply has
  no button. One line of copy ("your next bundle goes out on Friday") would close the gap.

### 10.2 Stock ordering and the ledger — **correct, double-entry reconciles**

Order 6 (dealer buys from distributor) wrote four ledger rows and they balance exactly:

```
 8  dealer      1  stove   +10   +350,000  purchase       order 6
 9  distributor 1  stove   -10   -350,000  transfer_out   order 6
10  dealer      1  tuktuk  +10   +600,000  purchase       order 6
11  distributor 1  tuktuk  -10   -600,000  transfer_out   order 6
```

Balances after every movement including two sales: distributor 94 stove / 90 tuktuk,
dealer 9 stove / 10 tuktuk. Every unit is accounted for.

Validation on the order form was probed two ways and held both times:

- Zero of everything → refused, with a message someone can act on:
  *"Enter how many you want of at least one product."*
- `min` stripped from the number input and `-5` posted → refused server-side. No order row,
  no ledger row. The browser's constraint is not the only thing standing there.

Note: `stock_orders.reference` is the payment reference for the transfer and accepts
anything — the two existing rows read `34t5yuio9` and ``tcvgbhjkl,;.'/``. It is the field
that ties a stock order to a bank transaction, so a light format check, or at least a
minimum length, would stop a keyboard-mash being filed as a UTR.

### 10.3 Blog — **works; two things worth deciding**

Created, published, and read back through `blog.php` and the public `/blog` page. The slug
is derived from the title and a collision is handled (`…-2`).

- **A cross-site scripting attempt did not fire.** A post titled
  `QA xss <img src=x onerror=window.__xss=1>` with `<script>` in the body rendered as
  literal text; neither payload executed. `blog.php` returns the raw string in JSON and
  `main.js` inserts it as text, which is the right defence and it holds.
- The consequence is that **a post cannot carry any formatting at all** — no bold, no links,
  no headings. `<b>bold</b>` appears on the page as the characters `<b>bold</b>`. For a blog
  whose stated subject is *"notes on the fuel, the hardware and what it takes"*, no links is
  a real editorial limit. If formatting is wanted, the safe route is a small allow-list
  (Markdown for emphasis and links, rendered server-side) rather than trusting the field.
- A post saved with status `published` stores `publish_at` as `NULL`. It publishes
  immediately, which is presumably intended, but `status='scheduled'` with a null
  `publish_at` would be a contradiction the schema does not prevent.

### 10.4 Raffle — **works; the office cannot actually draw**

- The finder is sound. `raffle-search.php?q=Stellar&draw=1` returns the applicant with a
  note that they are *"already on draw 1"*, so a duplicate cannot be added by accident.
- An SQL-injection probe (`' OR 1=1--`) returned *"No applicant … matches `&#039; OR 1=1--`"*
  — parameterised, and the echo is escaped.
- Eligibility is computed live and is correct: three applications with a verified booking
  payment, `raffle_eligible_count()` returns 3.
- **The draw is recorded, not performed.** The only actions on `admin/raffle` are `setup`,
  `toggle`, `add` and `remove`. Winners are typed in by hand from the search box, and
  `raffle_draws.drawn_at` / `drawn_by` are never written. For a public promotion offering
  gold, "somebody typed three names in" is a weaker story than a recorded, timestamped draw
  from a stated pool. Worth deciding whether that is the intent.
- **The admin page promises something the settings switch off.** With `raffle_enabled = 0`,
  `admin/raffle` still shows *"Draw 1 · 3 WINNERS · 1 G OF GOLD EACH · GOES PUBLIC IN
  13d 02h 57m"*. It will not go public in thirteen days; it will not go public at all until
  the toggle is on. The countdown should say so.
- `raffle_draws.pool_size` is never written by anything. The public `poolSize` is computed
  live from `raffle_eligible_count()`, so nothing is broken, but the column is dead and
  reads as authoritative.

### 10.5 Injection and abuse probes — all clean

| Probe | Where | Result |
|---|---|---|
| `' OR 1=1--` | `raffle-search.php?q=` | Parameterised; echoed escaped |
| `<img src=x onerror=…>` | blog title | Rendered as text, did not fire |
| `<script>…</script>` | blog body | Rendered as text, did not fire |
| `../config.php`, `..%2f`, `....//`, `/etc/passwd` | `admin/file.php?path=` | All refused |
| Negative quantity, `min` stripped | `distributor/stock` | Refused server-side |
| Duplicate winner | `admin/raffle` | Flagged "already on draw 1" |
| Voucher cycle run twice | `cron/voucher-run.php` | Nothing raised the second time |

### 10.6 Payment rejection — read, not clicked — **one defect, now fixed**

The Reject control only renders while a payment sits at `pending`, and by the second pass
every payment in the database was `verified`, so there was nothing to reject.
`admin/partials/payment-panel.php:178` was read instead: it posts `action=reject` with a
CSRF token, a `data-confirm` ("The applicant is emailed the reason") and a `reason` text
field carrying a `visually-hidden` label and `maxlength="255"`.

One thing stood out in that markup: **the reason field was not `required`**, and
`admin/payment.php` stored `NULL` for an empty one before sending the email —
`send_payment_rejected_email($app, $reason = '')` defaults it to an empty string and nothing
downstream fills the gap. **Fixed** — see §3.5.

### 10.7 Test data added by this pass

Folded into §9, which is now the single cleanup list for both passes. The one item that
should not wait: **`blog_posts` 2 and 3 are XSS probe posts, published and visible on
`/blog` right now.** They are harmless — the payload does not execute — but they are QA
litter on a public page. Delete them, and post 1 with them.

---

## 11. Third pass — closing out what had been deferred

### 11.1 Every email template, rendered and audited

All twenty were rendered to HTML and plain text with `mailer.php` loaded under renamed
network functions, so nothing was sent and the repository was untouched. Total 68 KB of
HTML across the set; the largest is `receipt` at 6.6 KB with a PDF attached.

**Construction: as good as email markup gets.** Every count below is across all twenty:

| Construct email clients break on | Occurrences |
|---|---|
| `display:flex` / `display:grid` | 0 |
| `position:absolute` / `fixed` / `sticky` | 0 |
| CSS custom properties (`var(--…)`) | 0 |
| `<style>` blocks | 0 |
| `class="…"` | 0 |
| External stylesheets | 0 |
| `<script>` / `<form>` | 0 |
| `background-image` | 0 |
| `<svg>` | 0 |
| `.webp` images | 0 |
| `<img>` without `alt` | 0 |
| `<img>` without `width` | 0 |

Every layout is a `role="presentation"` table, every template is 600 px max-width, all
inline styles. Outlook's Word rendering engine will handle this. The plain-text alternative
is generated for all twenty and lands between 505 and 1,249 bytes — real content, not a
stub.

**What is missing** — `lang`, dark mode and a preheader, on all twenty: §4.15. **What is
wrong** — the QR-only payment instrument: §4.14. **What was broken and is now fixed** —
`base_url()` from the command line: §3.6.

One template threw during the render, `voucher_update`, and it was the fixture rather than
the code: `email_rows()` takes a label-to-value map and the test passed a list, so the
array key reached `e()` as an `int`. Every real caller passes a map. Not a defect.

Two subjects came out reading *"Reminder: the booking payment of ₹0.00 is due"* and *"Your
referral reward of ₹0.00 is on its way"*. Both are artefacts of pointing the fixture at a
completed application with no referral, and neither is reachable from the interface — the
reminder bell only renders at `booking_pending` or `delivery_pending`. Worth a guard
anyway, since `payment.php?action=remind` is reachable directly and a zero-value reminder
is a strange thing to send anybody.

### 11.2 Payment rejection, end to end

Driven on a fresh application (`MF-00000553`) through submit, approve, receipt upload,
rejection and re-upload. The §3.5 fix was confirmed live from both directions:

```
empty reason, in the browser  -> blocked, "Please fill out this field."
                                 payment untouched: pending / NULL
empty reason, posted by hand  -> 422 "Say why the payment is being turned down"
                                 payment untouched: pending / NULL
```

The real rejection then behaved correctly at every point:

- `payments` row → `rejected`, with the reason, `decided_at` and `decided_by`.
- The application returned to `booking_pending` — not left stranded at `booking_review`.
- The applicant was emailed *"We could not verify your payment — MF-00000553"*.
- **The proof file was deleted from disk.** Verified: the uploaded PDF is gone from
  `admin/uploads/payments/`. Refused evidence is not kept, which is the right call and an
  easy one to forget.
- The portal showed the reason in full — *"Your last receipt was not accepted: The UTR does
  not match any transfer we received…"* — put the stage back to **Due now**, and offered
  the upload again.
- Re-uploading created a second `payments` row and left the rejected one in place, so the
  history of what was tried survives.

`applications.payment_rejected_at` stayed `NULL` throughout, consistent with the dead
columns in §4.12.

### 11.3 The raffle, switched on

`raffle_enabled` was set to `1` through the admin toggle, the public side checked, and the
setting put back to `0`. It is off again.

The reveal is a popup behind a nav button, not a page section, and it is right. With the
promotion off both triggers carry `hidden` and there is nothing to open. With it on:

- A live countdown — *"13 DAYS 02 HOURS 02 MINUTES 50 SECONDS"* — ticking, against
  *"Draw 1 · 15 Sep 2026, 12:00 am"*.
- The prize, the terms, and a cash alternative computed rather than hard-coded:
  *"5–7% under the market value of 1 gram — about ₹6,510–₹6,650 at today's ₹7,000 a
  gram"*, which is exactly the 5–7% discount range in `settings`.
- A correct empty state: *"No draw has been held yet. The first 3 winners appear here the
  moment they are drawn."*
- The public JSON matched throughout: `enabled: true, running: true, poolSize: 3`, with the
  next draw and the cash range.

**The pool count contradicts the sentence printed beside it.** The popup reads:

> 3 applicants are in the next draw. … and a past winner does not go back in.

`raffle_eligible_count()` is `SELECT COUNT(*) FROM applications WHERE booking_paid_at IS
NOT NULL AND status <> 'rejected'` — no join to `raffle_winners`. With one winner already
recorded on draw 1, the honest figure is **2**, not 3. `raffle_search()` twenty lines
further up *does* join `raffle_winners` and flags "already on draw 1", so the module knows
how; the count is the one place it does not. On a promotion whose whole pitch is that it is
drawn fairly in front of witnesses, publishing a number that contradicts the rule beside it
is worth more than the one-line fix costs.

**The payout half of the raffle does not exist.** `raffle_winners.payout_status`,
`prize_choice`, `cash_amount`, `paid_at`, `note` and `shuffles` are written and read by no
code; `raffle_draws.drawn_at`, `drawn_by` and `pool_size` likewise. `raffle-lib.php` only
ever runs `INSERT INTO raffle_winners (draw_id, application_id, position)` and a `DELETE`.
Recording that somebody won is built; everything after it — did they take gold or cash, was
it handed over, when — is schema with nothing behind it.

**Accessibility:** the popup moves focus to its close button, which is right, but carries no
`role="dialog"` and no `aria-modal`. The admin's own confirmation dialog (`admin.js:64`)
sets `role="alertdialog" aria-modal="true"`; the raffle popup should match it.

### 11.4 Load and concurrency

150 requests per endpoint at 25 concurrent, plus a single-connection baseline. Milliseconds.

| Endpoint | Conc. | Failed | req/s | p50 | p90 | p99 | max |
|---|---|---|---|---|---|---|---|
| `/` | 1 | 0 | 40 | 22 | 49 | 60 | 60 |
| `/` | 10 | 0 | 97 | 80 | 211 | 324 | 541 |
| `/` | 25 | 0 | 216 | 93 | 216 | 319 | 333 |
| `/apply-stove` | 25 | 0 | 334 | 67 | 102 | 122 | 125 |
| `/blog.php` | 25 | 0 | 212 | 112 | 151 | 204 | 209 |
| `/raffle.php` | 25 | 0 | 134 | 161 | 308 | 351 | 367 |
| `/portal/` | 25 | 0 | 79 | 231 | 528 | 846 | 952 |
| `/admin/login` | 25 | 0 | 125 | 168 | 311 | 480 | 526 |

**Zero failures, every response 200, no dropped connections and no timeouts.** Nothing
degraded into errors under contention, which is the thing this test was for.

`/portal/` is the slowest by a factor of three — p50 231 ms against 93 ms for the home page,
p99 846 ms. It starts a session and calls `portal_roles()`, which queries `applications`,
`dealers` and `distributors` before deciding anything. It is also the page a customer
arrives at from a payment email, so it is the worst one to have as the slowest. Three
queries collapsed into one, or a role cached in the session, would take most of that back.

Twenty round trips of the heaviest reporting query (`applications` left-joined to
`payments`, grouped) averaged 157 ms with a 356 ms worst case, so the database is not what
`/portal/` is waiting on.

**This is a smoke test, not a capacity number.** Apache `mpm_winnt` on a developer laptop
that was also running Chromium, over loopback. It says the application does not fall over
when requests overlap. It says nothing about what the production host will carry.

---

## 12. Order of work

**Before this is reachable from the internet**

1. **Rotate the SMTP password** and get `admin/config.php` out of Git — it was web-readable
   and it is in history. (§3.2)
2. **Delete the two XSS blog posts.** They are published and live on `/blog` right now.
   (§10.7)
3. Change `admin12345` and `rf123`, and delete both from `admin/README.md` and
   `CLIENT-FLOW.md`. (§4.4)
4. Take the six-digit sign-in code out of the email subject line. (§4.2)
5. Set `PUBLIC_BASE_URL` to the production address. It is set to the local one now, and a
   wrong value puts a dead link in every email. (§3.6)

**Email delivery — the biggest risk in the system**

6. **Retry failed sends.** Nothing does today: `email_log.ok = 0` and the message is gone.
   The provider's own reply was "try again later". (§4.13)
7. **Show failures in the admin.** A list reading `email_log WHERE ok = 0`. The office
   currently has no way to know a payment email did not arrive. (§4.13)
8. **Move transactional mail to a sender built for it** (SES, Postmark, Brevo). A shared
   mailbox hit its hourly cap during a QA session. (§4.13)
9. Put the UPI id and a `upi://pay` link beside the QR code, as text. Images are blocked by
   default in Outlook and Gmail, and nobody can scan a QR on the phone they are reading it
   on. (§4.14)

**Cheap and high value**

10. Uncomment `mod_deflate` and `mod_expires` in `httpd.conf`, restart Apache, re-measure.
    The rules are already written. ~360 KB off the home page. (§4.1, §6.1)
11. Add `width`, `height` and `loading="lazy"` to every `<img>`. Nothing on the site has
    any of the three. (§6.2, §6.3)
12. Decide `.com` or `.co.in` and make all 22 references agree, `Message-ID` included.
    (§4.3)
13. Fix the raffle pool count so it excludes past winners, or change the sentence beside it.
    It currently says 3 where the rule it prints makes it 2. (§11.3)
14. Fix the raffle countdown copy — it promises a reveal the settings have switched off.
    (§10.4)

**Conversion and clarity on the apply form**

15. Stop disabling the submit button; let the existing red-field validation explain itself.
    (§4.7)
16. Add a confirmation to Approve — it emails a stranger a payment demand, and it is the
    only control in the panel that does not ask. (§4.8)
17. Add a step counter, a draft save and an error summary; make the fifteen identical
    "This one is needed." messages specific. (§5)
18. Give the country-code selector country names. (§4.9)

**Accessibility**

19. Fix the three contrast tokens — exact replacements in §7.1.
20. Add a skip link (no page has one); fix the heading-level skips on seven of ten pages;
    fix the missing focus ring on the `TukTuk` nav link; pad the sub-40 px touch targets.
    (§5)
21. Give the raffle popup `role="dialog"` and `aria-modal="true"` to match the admin's own
    confirmation dialog. (§11.3)
22. Add `lang`, a preheader and a `prefers-color-scheme` block to the email wrapper — one
    change in `email_wrap()` covers all twenty templates. (§4.15)

**Data integrity and abuse**

23. Populate `commission_lines.rate`. (§10.1)
24. Rate-limit `admin/submit.php` — six newsletter posts in a row were all accepted, and
    each one sends mail. (§4.5)
25. Make the portal sign-in reply uniform so it stops distinguishing unknown / pending /
    customer for any address typed at it. (§4.6)
26. Guard the reminder and referral emails against a zero amount. (§11.1)
27. Add a light format check to `stock_orders.reference`. (§10.2)
28. Speed up `/portal/` — three role queries on every request, and it is three times slower
    than any other page. It is where a payment email lands. (§11.4)

**Decisions rather than fixes**

29. Should a blog post be able to carry links and emphasis? It cannot today. (§10.3)
30. **Build the raffle payout, or take the columns out.** Recording a winner is built;
    prize choice, payout and paid-date are schema with no code. (§11.3)
31. Should `distributor/payouts` say when the next bundle goes out? (§10.1)
32. Is the cookie bar right as a hard gate, and should it autofocus Accept? (§5)
33. Drop the dead columns, or document why they stay. (§4.12, §10.4, §11.2)

Items 1–9 are the ones that should not wait for a release. 6 to 8 are one problem wearing
three hats: **the system has no idea when its email does not arrive, and it has already not
arrived.**
