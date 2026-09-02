# Applicant portal

Where an applicant tracks their application and uploads proof of payment.
Reached from the **Login** button in the site navbar.

## Sign in

No passwords. The applicant types the email address they applied with, and a
six-digit code is emailed to it. The code lasts 10 minutes, allows 5 wrong
guesses, and is limited to 6 requests per hour counted per address **and** per
IP (`login_attempts`, under an `otp:` prefix).

Nothing about the code is stored. `issue_otp()` keeps an HMAC of the address,
the expiry and the code in the session — `otp_signature()` — and `verify_otp()`
recomputes it from what was typed. There is no `applicant_otps` row to read, and
the six digits exist only in the email.

**Every address gets the same answer**: *"If that address is registered with us,
a six-digit code is on its way."* It used to say which of three states an
address was in — unknown, application pending, registered — which with no
throttle in front of the form made it a way to walk a list of addresses and read
back which ones we hold. The explanation an applicant still waiting on the
office needs has moved into an email only they can read
(`send_application_waiting_email()`).

The code step is reached whatever was typed. A code entered against an address
no code went out for simply does not match the signature, because there is none.

## What the applicant sees

A card per application showing the booking number (`MF-00000042`), the product, and
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
