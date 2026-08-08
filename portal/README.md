# Applicant portal

Where an applicant tracks their application and uploads proof of payment.
Reached from the **Login** button in the site navbar.

## Sign in

No passwords. The applicant types the email address they applied with, and a
six-digit code is emailed to it (`applicant_otps`). The code lasts 10 minutes,
allows 5 wrong guesses and is limited to 6 requests per address per hour.

The page gives the same answer whether or not the address is known, so it cannot
be used to find out who is a customer. A code is only actually generated and
sent when an application with that address exists.

## What the applicant sees

A card per application showing the reference (`MF-2026-00042`), the product, and
a five-stage timeline:

| Stage | Meaning |
|---|---|
| Application received | submitted through the website |
| Under review | admin is checking the details |
| Confirmed — payment due | approved; QR code emailed, receipt needed |
| Payment submitted | receipt uploaded, admin verifying |
| Complete | payment verified, installation to be scheduled |

Rejected applications show a plain message instead of the timeline.

## Uploading a receipt

The upload form only appears while an application is `confirmed`. It accepts
JPG, PNG, WebP and PDF up to 10 MB, stores the file in
`admin/uploads/payments/` under a random name, records an optional UTR, and
moves the application to `payment_pending` for the admin to verify.

Receipts are not reachable by URL — `admin/uploads/payments/.htaccess` denies
direct access, and only a signed-in admin can open them through
`admin/file.php?dir=payments`.

## Files

| File | Purpose |
|---|---|
| `index.php` | Email → one-time code sign-in |
| `status.php` | Application list, timeline and receipt upload |
| `logout.php` | Ends the applicant session |
| `lib.php` | Session, OTP issue and verify, lookups |
| `partials/` | Site header and footer for portal pages |

Styling reuses the public `assets/css/style.css` (section 13f), so the portal
matches the rest of the site.
