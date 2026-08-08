# Manifold admin

Submission inbox for the four website forms: the two product applications, the
contact enquiry form and the footer newsletter box. PHP 7.4+ with PDO MySQL, no
framework, no build step.

## Setup

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
| `index.php` | Dashboard: totals per form, latest ten submissions |
| `list.php` | One form's submissions — filter, switch status inline, expand a row for the full record |
| `status.php` | POST handler behind the row action buttons and the note box |
| `delete.php` | Deletes one submission and its uploaded files, POST only |
| `file.php` | Serves an uploaded document to a signed-in admin |
| `submit.php` | Public endpoint the website forms post to |
| `create-admin.php` | One-time bootstrap, delete after use |
| `partials/` | Shared sidebar, page chrome and the row status switcher |
| `assets/admin.css` | All admin styling |
| `assets/admin.js` | Auto-submitting status selects, expandable rows |
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

**Applications** follow the payment flow:

`new → pending → confirmed → payment_pending → complete`, or `rejected`.

| Icon | Action | Effect |
|---|---|---|
| ⏳ | Mark under review | `pending` |
| ✓ | Confirm | `confirmed` **and emails the payment QR code and portal link** |
| ✅ | Verify payment | `complete` **and emails the applicant** |
| ✕ | Reject | `rejected` |
| 🗑 | Delete | removes the record, its documents and its receipt |

`payment_pending` is set by the applicant, not the admin — it happens the moment
they upload a receipt in the portal. That is the queue to review.

Once an application is `complete` the status buttons disappear and a "Done"
marker takes their place; the server rejects any further status change on it.
Delete stays available so finished records can still be cleared out.

**Contact enquiries and newsletter signups** keep the simple set: accept ☎
contact, ✕ reject, 🗑 delete.

The button matching the current status is filled in and disabled. Delete asks
for confirmation first and cannot be undone; there is no soft-delete, so use
Reject if the record should be kept. Nothing sets a record back to `new` — use
the status filter to find them instead.

"Details" expands the row in place to show every field and the internal note
box, so there is no separate record page.

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
