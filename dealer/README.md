# Dealer portal

Where a dealer sees what they have sold and what they have been paid. Reached
from the **Dealer login** button in the portal navbar.

## Sign in

The same one-time code the applicant portal uses, asked for with the `dealer`
audience: `issue_otp($email, 'dealer')` only sends a code to an address held
against an **active** dealer, and `verify_otp(..., 'dealer')` checks that again
before the code signs anybody in. A dealer with no email address on their record
cannot sign in until the office adds one.

Applicants and dealers share the `applicant_otps` table and its throttle (6 per
address per hour, 10-minute life, 5 wrong guesses). A code proves somebody reads
that mailbox; what it opens is decided by the audience check, not the code.

Switching a dealer off in the admin ends their session on the next request —
`dealer_user()` re-reads the row every time rather than trusting the session.

## What a dealer sees

Three screens, all read-only. Nothing in this folder writes to an application, a
payment or a payout.

| Screen | Shows |
|---|---|
| Dashboard | totals, their two share links, five most recent clients |
| Clients | everyone who applied through their link, with a progress bar |
| Payouts | every transfer the office has made to them |

## What a dealer never sees

`dealer_client_view()` is the whole allow-list — a client row is rebuilt field by
field rather than passed through. A dealer introduced these people; they did not
become their bank. Deliberately absent:

- payment proofs and receipts (`payment_proof_path`, `receipt_no`, UTR references)
- identity and address documents
- the customer's home address
- amounts outstanding — the progress bar says which of five stages a sale is at,
  never how much is owed
- anything belonging to another dealer

Commission is shown per client because it is theirs, and it only counts once the
customer's booking payment has been verified and the application is not rejected.

## Header actions

Both dropdowns are the same two links: **Copy link** puts one on the clipboard,
**Add a client** opens the apply form in a new tab. Either way the dealer's code
arrives in the referral box already filled in and locked, which is the only way a
sale gets attributed to them.

## Files

| File | Purpose |
|---|---|
| `login.php` | Email → one-time code sign-in |
| `index.php` | Dashboard |
| `clients.php` | Their customers and each sale's progress |
| `payouts.php` | Transfers received |
| `logout.php` | Ends the dealer session |
| `lib.php` | Session guard and the client allow-list |
| `partials/` | The admin shell, with three items in the rail |

Styling reuses `admin/assets/admin.css`, so the portal matches the dashboard the
office uses.
