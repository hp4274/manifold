# Manifold admin

Submission inbox for the four website forms: the two product applications, the
contact enquiry form and the footer newsletter box. PHP 7.4+ with PDO MySQL, no
framework, no build step.

## Setup

1. **Create the database.** Import `schema.sql` —
   `mysql -u root -p < schema.sql`, or paste it into phpMyAdmin. It builds the
   `manifold` database with every table empty, the default settings and one
   admin account:

   | username | password |
   |---|---|
   | `admin` (or `admin@manifold.com`) | `admin12345` |

   **It resets any existing tables in the `manifold` database**, so back up before
   running it on a machine that already has data. **Change that password**
   before the site goes anywhere real.

   `schema.sql` is the only SQL file here and always describes the current
   structure — there are no migrations to replay in order. On a database that
   already holds real data, diff it against `mysqldump --no-data` and apply the
   difference by hand rather than importing.
2. **Check the credentials** in `config.php`. Stock XAMPP defaults (`root`, no
   password, `127.0.0.1`) are already set.
3. Sign in at `http://localhost/manifold/admin/login.php`.
   `create-admin.php` is still there for making the first account by hand on a
   database seeded without one — **delete it after use**.

## Lists and paging

Every form's list shows **ten rows a page**. `LIST_PER_PAGE` at the top of
`list.php` is the only place that number lives; changing it changes the query, the
numbering and the pager together.

- Row numbers keep counting across pages, so #11 is the eleventh record, not the
  first on page 2.
- The status filters above the table always go back to page 1, and each of them
  pages separately — "New 10" is its own two pages if there are more than ten.
- A page number past the end lands on the last real page. That is also what
  happens when the last row on the last page is deleted.
- Saving or deleting a row returns to the page it was on, not to page 1.
- The pager (`partials/pager.php`) draws nothing at all when everything fits on
  one page, and only ever renders first, last and the pages either side of the
  current one, so a long list does not grow a hundred links.

Only the ten rows on screen get their detail drawer built, which is what keeps the
page small however many records there are.

The dashboard is different on purpose: it shows the newest ten across all four
forms and does not page. It is a glance at what has just come in.

## Files

| File | Purpose |
|---|---|
| `schema.sql` | The whole database: tables, default settings, seeded `admin` account |
| `config.php` | Credentials, upload rules, PDO connection |
| `lib.php` | Session, CSRF, login guard, status vocabulary |
| `login.php` / `logout.php` | Authentication |
| `index.php` | Dashboard: totals per form, latest submissions with column filters |
| `list.php` | One form's submissions — filter, switch status inline, expand a row for the full record |
| `status.php` | POST handler for contact / newsletter row buttons |
| `payment.php` | Accept or reject one transfer, or chase the balance |
| `delete.php` | Deletes one submission and its uploaded files, POST only |
| `file.php` | Serves an uploaded proof (identity, address or payment) to a signed-in admin |
| `receipt.php` | Generates the PDF receipt for one verified payment |
| `receipt-pdf.php` | The PDF writer and the receipt layout, no dependencies |
| `submit.php` | Public endpoint the website forms post to |
| `blog.php` | Blog posts — write, schedule, publish, unpublish, delete |
| `referrals.php` | Referral payouts — who owes whom, mark a reward sent |
| `settings.php` | The referral reward, the accounts that can sign in, and the sample data |
| `seed-lib.php` | The sample data itself, and the add/remove/count functions |
| `seed-sample.php` | The same sample data from the command line |
| `create-admin.php` | One-time bootstrap, delete after use |
| `partials/` | Sidebar, page chrome, row actions, detail drawer, payment panel |
| `assets/admin.css` | All admin styling |
| `assets/admin.js` | Column filters, detail drawer, delete confirmation |
| `uploads/` | Uploaded ID and address documents (not web-readable) |

### Raffle files

| File | What it is |
|---|---|
| `raffle.php` | The Raffle screen: the countdown, the search, the winners, setup |
| `raffle-lib.php` | The cycle, the search, add and remove, masking — no markup |
| `raffle-search.php` | Answers the search box as it is typed. Signed-in staff only |
| `partials/raffle-results.php` | The result list, rendered by both of the above |
| `partials/raffle-winners.php` | The winners table, shared by every draw panel |
| `../raffle.php` | The public feed the popup on the website reads |

## Email (SMTP)

`config.php` has `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER` and
`SMTP_PASS` — **left blank on purpose, fill them in**. Until they are set the
site still works, but no email leaves the server: the admin shows "the email
could not be sent" and the reason is written to the `email_log` table.

`mailer.php` speaks SMTP directly (AUTH LOGIN over TLS or SSL) so there is no
Composer dependency. Gmail needs an app password, not the account password.

The payment QR code is picked up from the first of these that exists:
`qr.jpeg`, `qr.jpg`, `qr.png`, `assets/images/qr.jpeg`, `assets/images/qr.png`
(see `qr_path()` in `config.php`). It is embedded in the confirmation email as
an inline image and shown on the payment step in the portal, so an applicant can
scan it from either place. If no file is found, the email says the QR will
follow separately.

Every body lives in `emails.php` and shares `email_wrap()`, so all of them look
the same. A contact enquiry gets an immediate thank you
(`send_contact_thanks_email()`, logged as kind `contact_thanks`) quoting the
message back to the sender; a bad address only costs a failed row in
`email_log`. A new newsletter address gets a welcome
(`send_newsletter_welcome_email()`, kind `newsletter_welcome`); an address that
is already on the list only has its record refreshed and is not written to
again. There is no unsubscribe link — the email asks people to reply, and the
office removes the row.

## Statuses

Every submission moves through `new → accepted → contacted → rejected` (any
order; the four are just labels). Counts per status are on the dashboard tiles
and the filter row of each list. Each change is written to `status_log` with the
admin who made it.

**Applications** are payment-first, and every one is exactly two transfers: a
booking amount with the application, then a delivery amount when the unit is
handed over. The rest of the price is the applicant's own loan and never passes
through here. Both figures live in `PAYMENT_PLAN` (`config.php`) and are frozen
onto the application row at submit time, so a later price change never rewrites
what an open application owes.

| Product | Booking | Delivery |
|---|---|---|
| Stove (KH-100) | ₹3,500 | ₹16,500 |
| TukTuk kit (MH-3W) | ₹6,000 | ₹24,000 |

`booking_pending → booking_review → delivery_pending → delivery_review →
complete`, or `rejected`.

1. The form is submitted. `submit.php` emails the applicant the reference, the
   QR code and the booking amount. The record lands on **booking payment
   pending**.
2. The applicant pays the booking amount and uploads the proof. The upload
   becomes a row in `payments` with `stage = 'booking'` and the application
   moves to **booking receipt — verify**.
3. An admin opens **Details** and accepts or rejects it. Accepting emails a
   numbered receipt (`MF-2026-00042-R1`) and moves the application to **delivery
   payment pending**; the delivery upload only opens for the applicant at that
   point. Rejecting puts the booking payment back to due, with the reason
   emailed and the proof deleted.
4. The delivery payment repeats the same two steps with `stage = 'delivery'`.
   When both are verified the application flips to **complete** on its own.

A verified booking payment — not completion — is what earns the referral reward
and enters the applicant in the raffle; `booking_paid_at` on the application row
is the flag both read.

The application's status is always derived from its payments
(`status_from_payments()` / `sync_application_status()` in `lib.php`), so it can
never drift out of step with what has actually been banked.

Everything about a payment happens in the Details drawer, where the receipts can
actually be seen — application rows carry no status buttons, only delete.

| In the drawer | Effect |
|---|---|
| Accept & send receipt (per transfer) | that payment is verified, its receipt is emailed, the balance drops |
| Reject (per transfer) | that payment is void, applicant emailed the reason, its file deleted |
| Send payment reminder | emails the QR code and the outstanding balance |

The fee is `PAYMENT_AMOUNT` in `config.php` (₹3,500), copied to each
application's `payment_amount` at creation so changing the constant does not
rewrite historic records.

**Contact enquiries and newsletter signups** keep the simple set on the row
itself: ✓ accept, ☎ contact, ✕ reject, 🗑 delete.

"Details" opens a slide-over on the right with every field — and, for an
application, the payment panel at the top. There is no separate record page.

## Proofs and receipts are different things

| Artefact | Who makes it | Where it lives | How it is opened |
|---|---|---|---|
| Identity / address proof | applicant, on the apply form | `admin/uploads/` | `file.php?path=…` |
| Proof of payment | applicant, per transfer | `admin/uploads/payments/` | `file.php?path=…&dir=payments` |
| **Receipt** | **we do, per verified payment** | **generated on demand, never stored** | `receipt.php?payment=…` |

A receipt is a PDF we issue — it is not the applicant's screenshot. Rejecting a
payment deletes that transfer's proof but never affects receipts already issued,
because those are regenerated from the payment record each time.

Receipts are also attached to the receipt email and copied to
`ADMIN_NOTIFY_EMAIL`, so both sides always have one.

## Blog

Posts live in `blog_posts` and are written at **Blog** in the sidebar. Four
states:

| Status | On the website |
|---|---|
| `draft` | Invisible. Nothing outside the admin can reach it |
| `scheduled` | Invisible until `publish_at` passes, then live on its own |
| `published` | Live |
| `unpublished` | Pulled down, kept for later |

A scheduled post needs no second visit: `blog_live_posts()` treats
`scheduled` with a past `publish_at` as published, and the list shows it as
Published with a note that its date has passed.

The pages are static HTML, so they fetch `/blog.php` (root, public, read-only
JSON — not to be confused with `blog.html`, the page) and render the cards
themselves. The home page shows four and fades into a View more that leads to
`blog.html`, which lists the lot. Read
more slides the whole piece out of the right-hand edge; the body is plain text
and each blank line becomes a paragraph, so nothing an author types can inject
markup.

Pictures go to `assets/images/blog/` rather than `admin/uploads/`, because
the public site has to load them and `uploads/` is deliberately closed.
Deleting a post removes its picture, but only when the file sits in that
folder.

## Referrals

Every application is given a permanent code of its own (`MF` plus six
characters, no O/0 or I/1) the moment it is submitted.

The code only starts working once that application is **paid in full** — that is
when it is handed over, printed on the final receipt PDF and shown in the
portal with ready-made apply links (`apply-stove.html?ref=CODE`).

Quoting a code costs the new applicant nothing and saves them nothing: they pay
the full fee. What it does is book a reward for the owner of the code, held on
the referred application as `referral_reward` with a status:

| Status | Meaning |
|---|---|
| `none` | Not referred, or the code did not match anything |
| `pending` | Owed. Only payable once the referred applicant has paid in full |
| `sent` | The office has transferred it and the referrer has been emailed |
| `cancelled` | Written off by the office |

**Nothing is paid automatically.** `referrals.php` lists every referral with
both sides, the referred applicant's own payment state and the reward status.
"Mark sent" stays disabled until that applicant is `complete`; using it stamps
`referral_reward_sent_at`, records which admin did it and any UPI/UTR note, and
emails the referrer. Rows can be cancelled or put back to pending.

The amount is `settings.referral_reward`, edited in **Settings** and seeded at
₹500. Each referral snapshots the figure that applied on the day it came in, so
changing the setting never rewrites a payout already owed.

An unrecognised code is stored as typed in `referred_by_code` with no reward,
and the payment email says so — the application is never rejected over a typo.

## Raffle

Every cycle (90 days as advertised) five applicants who have **paid in full** win
one gram of pure gold each, or the cash value of it less 5–7%.

**Nothing is drawn here.** The office holds the draw however it likes — in front
of witnesses, as the promotion says — and then records who won. The Raffle screen
does two things: it counts down to the next reveal, and it lets you put names
against it.

### The calendar

Only one date is ever entered: **first draw revealed at**, under *Raffle setup*.
Every following reveal is that date plus a whole number of cycles, so the calendar
looks after itself. Leave the date empty and the raffle is not running — the popup
on the website says the dates are still to be announced.

A draw row is created for each cycle so winners have something to hang off.
`raffle_sync()` does that whenever the Raffle screen or the public feed is loaded.
It picks nobody.

### Recording a winner

One search box, three ways in:

| Type | Matches |
|---|---|
| Part of a name | `patel`, `meera` |
| A reference code | `MF-2026-00031` |
| A mobile number | `9773444404`, `+91 97734 44404`, `977-344-4404` |

Results appear as you type — a quarter of a second of quiet and the list refreshes,
no button to press. `raffle-search.php` answers, and it returns the very markup
`raffle.php` renders (both `require partials/raffle-results.php`), so the live list
and the one you get on a plain page load cannot drift apart. An older answer that
arrives late is discarded rather than overwriting a newer one.

Without JavaScript the form still posts as a plain GET and the server renders the
same list; the Search button is there for exactly that case and is hidden once JS
has run.

`raffle-search.php` hands out applicants' names, numbers and email addresses, so a
request with no session gets a 401 and nothing else.

Only applicants with status `complete` are ever returned — that is the rule the
promotion is run on. A row that already holds a place says so, and one who won an
earlier draw is flagged, though nothing stops you adding them again if that is
what happened.

**Add** puts somebody in the lowest free place, so removing #2 and adding again
fills #2. **Remove** takes a name off. A draw refuses a name beyond its winner
count — raise *Winners per draw* in setup, or remove somebody first.

Lists stay editable, including after they are public. The office, not this screen,
decides what is right. Every add and remove is recorded in `status_log` under the
`raffle_winner` entity.

### What the public sees

`raffle.php` at the site root is the feed the popup reads. It returns draws whose
reveal time has passed and nothing else, and each winner as a masked name, a masked
mobile number and a city — `Harsh P. · 97******04 · Anand`. Full names, emails and
reference codes never leave the admin.

Nothing about who took the coin and who took the money is recorded here.

### The prize is not editable from the admin

*Raffle setup* covers the calendar only: whether the raffle runs, the first reveal
date, the cycle length and how many places a draw has.

The prize itself lives in four `settings` rows with no form behind them:

| Setting | Now | Drives |
|---|---|---|
| `raffle_gold_grams` | 1.000 | "1 gram of pure gold each" in the popup, snapshotted onto each draw |
| `raffle_gold_rate` | 15513.00 | The cash figure the popup quotes |
| `raffle_cash_discount_min` | 5.00 | The lower end of the band |
| `raffle_cash_discount_max` | 7.00 | The upper end |

Change them in phpMyAdmin (the `settings` table) or in `schema.sql` for a fresh
install. `raffle_config()` and `raffle_cash_range()` still read them, so the
website keeps quoting a figure — which means **a stale gold rate goes on being
shown to the public with no way to correct it from the admin.** Worth a look
whenever the market moves.

### Tables

`raffle_draws` (one row per cycle) and `raffle_winners` (one row per place), plus
eight `raffle_*` rows in `settings`. They are all in `schema.sql` along with
everything else — there is no separate raffle migration.

If the two tables are ever missing, the Raffle screen says so and points at
`schema.sql`. Copy the `CREATE TABLE` statements out of it rather than importing
the file, which drops the database.

Some columns are left from an earlier version that drew winners automatically and
recorded what each of them took: `pool_size`, `drawn_at` and `drawn_by` on
`raffle_draws`, and `prize_choice`, `cash_amount`, `payout_status`, `paid_at`,
`note` and `shuffles` on `raffle_winners`. Nothing reads or writes them now. They
are listed in `schema.sql` too, so the file still describes a database that has
been running.

## How the website reaches it

| Form | Page | Posted as |
|---|---|---|
| Stove application | `apply-stove.html` | `form=stove` |
| TukTuk application | `apply-tuktuk.html` | `form=tuktuk` |
| Contact enquiry | `contact.html` | `form=contact` |
| Newsletter | footer, every page | `form=newsletter` |

The apply forms post through `fetch` (see `assets/js/apply.js`) and show an
in-place thank-you panel. The contact and newsletter forms post normally and
come back to the page with `?sent=1`, which `assets/js/main.js` turns into a
toast.

## Sample data

Settings has a **Sample data** panel that fills every queue at once, so a new
database can be shown and tried out instead of sitting empty:

| | records |
|---|---|
| Stove applications | 20 |
| TukTuk applications | 20 |
| Contact enquiries | 40 |
| Newsletter signups | 50 |
| Blog posts | 10 |

The two application queues deliberately sit at different points of the payment
flow, so they do not read the same:

| | complete | payment review | payment pending | rejected |
|---|---|---|---|---|
| Stove | 8 | 5 | 5 | 2 |
| TukTuk | 5 | 4 | 8 | 3 |

Each application carries the payment row that justifies its status and is put
through `sync_application_status()` on the way in, so accepting and rejecting
behave exactly as they do with a real one. Of the ten posts, seven are live —
six published and one scheduled post whose date has passed — and the rest are a
scheduled post still to come, a draft and an unpublished one.

Everything is in `seed-lib.php`; the panel and the command line are two doors
onto the same functions.

    php admin/seed-sample.php            add it
    php admin/seed-sample.php --remove   take it out again
    php admin/seed-sample.php --status   say what is there

Adding twice is safe — anything already there is left alone. Every address is
`@example.com`, every IP is in 203.0.113.0/24 and every row carries a marker in
`admin_note` (posts are matched by slug), which is how removal finds this data
and nothing else. `seed-dummy.php` is the older, smaller set and still works.

**These rows are indistinguishable from real ones to anybody using the admin.**
On a live database the dashboard counts, the exports and the raffle pool all
include them — a sample `complete` application can be drawn as a winner. Take
them out before the site carries real traffic.
## Security notes

- Passwords are stored with `password_hash()`; logins are throttled to 8 failed
  attempts per email per 15 minutes.
- Every write is CSRF-checked; the session id is regenerated on sign-in.
- All queries are prepared statements; all output is escaped with `e()`.
- Uploads are limited to JPG, PNG, WebP and PDF up to 10 MB, renamed on save,
  stored outside the document flow and served only through `file.php`.
  `uploads/.htaccess` blocks direct access and script execution — confirm your
  server honours it (Apache with `AllowOverride All`); on nginx add the
  equivalent deny rule.
- Before going live: serve the admin over HTTPS, and consider adding a second
  factor or an IP allowlist.
