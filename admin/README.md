# Manifold admin

Submission inbox for the four website forms: the two product applications, the
contact enquiry form and the footer newsletter box. PHP 7.4+ with PDO MySQL, no
framework, no build step.

## Setup

0. **Upgrading an existing database?** Run `upgrade-portal.sql`,
   `upgrade-payment-flow.sql` then `upgrade-instalments.sql`, in that order.
1. **Create the database.** Import `schema.sql` once — `mysql -u root -p < schema.sql`
   or paste it into phpMyAdmin. It creates the `manifold` database with five
   tables plus the status audit log.
2. **Check the credentials** in `config.php`. Stock XAMPP defaults (`root`, no
   password, `127.0.0.1`) are already set.
3. **Create the first account** at `http://localhost/manifold/admin/create-admin.php`.
   The page refuses to run once an account exists — **delete it afterwards**.
4. Sign in at `http://localhost/manifold/admin/login.php`.

## Files

| File | Purpose |
|---|---|
| `schema.sql` | Database schema |
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
