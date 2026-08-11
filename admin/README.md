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

   **It drops any existing `manifold` database first**, so back up before
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
| `settings.php` | The referral reward, and the accounts that can sign in |
| `create-admin.php` | One-time bootstrap, delete after use |
| `partials/` | Sidebar, page chrome, row actions, detail drawer, payment panel |
| `assets/admin.css` | All admin styling |
| `assets/admin.js` | Column filters, detail drawer, delete confirmation |
| `uploads/` | Uploaded ID and address documents (not web-readable) |

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

## Statuses

Every submission moves through `new → accepted → contacted → rejected` (any
order; the four are just labels). Counts per status are on the dashboard tiles
and the filter row of each list. Each change is written to `status_log` with the
admin who made it.

**Applications** are payment-first, and the fee may be paid in instalments.

`payment_pending → payment_review → complete`, or `rejected`.

1. The form is submitted. `submit.php` emails the applicant the reference, the
   QR code and the ₹3,500 fee. The record lands on **payment pending**.
2. The applicant pays — all at once, or in as many transfers as they like — and
   uploads a receipt for each. Every upload becomes a row in `payments` and the
   application moves to **payment received**.
3. An admin opens **Details** and accepts or rejects each transfer separately.
   Each accepted transfer emails its own numbered receipt (`MF-2026-00042-R1`,
   `-R2`, …) showing the amount, the running total and the balance left.
4. When verified payments cover the fee, the application flips to **complete**
   on its own — nobody has to mark it.

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
