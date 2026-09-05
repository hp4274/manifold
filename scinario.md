# Test scenarios — Manifold, as the code actually behaves

Every scenario below is one that the current code can reach. They are ordered by
the order they happen in real life, so a run can go straight down the list and
each step leaves the data the next one needs. Every mail address used is a
YOPmail address.

Legend: **A** admin (`/admin`), **C** client/applicant (`/portal`),
**D** dealer (`/dealer`), **X** distributor (`/distributor`), **P** public site.

---

## 0. Ground rules the code enforces everywhere

| # | Scenario | Expected |
|---|---|---|
| 0.1 | POST any admin/portal action without the CSRF token | rejected — `csrf_check()` on every write |
| 0.2 | GET a page that is POST-only (`admin/status.php`, `admin/payment.php`) | redirected to the dashboard |
| 0.3 | Open any `/admin/*` page signed out | redirected to `admin/login.php` |
| 0.4 | Open `/portal/status.php`, `/dealer/*`, `/distributor/*` signed out | sent back to `/portal/` |
| 0.5 | Sign in as a dealer, then open `/distributor/` | refused — role is checked per area, not just "signed in" |
| 0.6 | `/dealer/login.php` | 302 to `/portal/` — one sign-in for everybody now |
| 0.7 | Fill the honeypot field `website` on any public form | silently accepted, nothing stored |
| 0.8 | Submit the same public form many times in a row from one IP | throttled by `submit.php` |

---

## 1. Public site (P)

| # | Scenario | Expected |
|---|---|---|
| 1.1 | Load `/`, `stove`, `tuktuk`, `technology`, `blog`, `contact`, `privacy-policy`, `apply-stove`, `apply-tuktuk` | 200, header and footer present, no console errors |
| 1.2 | Every internal link on the homepage | no 404 |
| 1.3 | Mobile width, tap the menu toggle | nav opens, links reachable |
| 1.4 | Blog index → open one published post | post renders; drafts and scheduled posts are not reachable |
| 1.5 | Raffle section on the public page | shows the countdown; winners appear only after the reveal time |

### 1.6 Contact form (`form=contact`)

| # | Scenario | Expected |
|---|---|---|
| 1.6.1 | Name, email, phone, message, consent → submit | thank-you; row appears in `admin/list?type=contact` with status `new` |
| 1.6.2 | Missing any of name / email / phone / message | rejected with a field error, nothing stored |
| 1.6.3 | Message longer than 5000 characters | rejected |
| 1.6.4 | Malformed email | rejected |

### 1.7 Newsletter box (`form=newsletter`)

| # | Scenario | Expected |
|---|---|---|
| 1.7.1 | Valid YOPmail address in the footer box | confirmed; row in the newsletter list |
| 1.7.2 | The same address again | accepted, no duplicate row |
| 1.7.3 | Invalid address | rejected |
| 1.7.4 | Open the unsubscribe link from the mail (GET) | page confirms removal |
| 1.7.5 | One-click `POST /unsubscribe.php` as a mail client does | 200, address removed |
| 1.7.6 | Unsubscribe with a wrong/absent token | refused — the token, not the address, is the proof |

### 1.8 Application form — stove and tuk-tuk (`form=stove` / `form=tuktuk`)

| # | Scenario | Expected |
|---|---|---|
| 1.8.1 | Submit with nothing filled | stays on the page, required fields flagged |
| 1.8.2 | All fields, both uploads, terms and declaration ticked | thank-you with a booking reference; row in `admin/list?type=<product>` at status **submitted** |
| 1.8.3 | Mobile number shorter/longer than the dial code allows | rejected with the exact digit count in the message |
| 1.8.4 | `date_of_birth` not a real date | rejected |
| 1.8.5 | Upload something that is not an allowed document type | rejected |
| 1.8.6 | Terms or declaration left unticked | rejected |
| 1.8.7 | Open `apply-stove.html?code=<dealerCode>` | `partner_code` is prefilled |
| 1.8.8 | Submit quoting a **dealer** code | application attributed to that dealer, and to the dealer's distributor above them |
| 1.8.9 | Submit quoting a **distributor** code | attributed to that distributor, no dealer |
| 1.8.10 | Submit quoting a **referral** code (`referral_code`) belonging to another client | referral row created for the referrer |
| 1.8.11 | Quote a code that does not exist | application still saved; the unresolved code is kept as an admin note |
| 1.8.12 | Quote your **own** code (same email or same last-10 mobile digits) | not counted as a referral — self-referral is blocked |
| 1.8.13 | Quote a code for a partner who holds stock | one unit deducted from that partner's stock on submission |
| 1.8.14 | Apply a second time from the same address | `portal/prefill.php` fills the personal fields from the newest application; nothing about payments, documents or consent is carried over |

---

## 2. Admin sign-in (A)

| # | Scenario | Expected |
|---|---|---|
| 2.1 | Correct email + password | dashboard |
| 2.2 | Wrong password | error, no session |
| 2.3 | Unknown address | same error — no hint that the address exists |
| 2.4 | Repeated failures for one address | throttled per address, and one address failing must not lock a colleague out |
| 2.5 | Blank email or password | "Enter both an email address and a password." |
| 2.6 | A disabled admin account | refused |
| 2.7 | Sign out, then press Back into a list | back at the sign-in page |

---

## 3. Admin: partners (A)

### 3.1 Distributor

| # | Scenario | Expected |
|---|---|---|
| 3.1.1 | Create a distributor with name only | created — everything except the name can be added later |
| 3.1.2 | Create with full details and a YOPmail address | row shows the address and a generated distributor code |
| 3.1.3 | Edit the distributor | changes stick; the code does not change |
| 3.1.4 | Toggle the distributor off | code stops working on the apply forms |
| 3.1.5 | Delete a distributor that has dealers under it | either refused or the dealers are detached — whichever the code does, it must not orphan a dealer silently |
| 3.1.6 | Copy the share link from the row | link points at an apply page carrying `code=` |

### 3.2 Dealer

| # | Scenario | Expected |
|---|---|---|
| 3.2.1 | Create a dealer under the distributor | row shows a dealer code, and the distributor it hangs under |
| 3.2.2 | Create a dealer with no distributor | allowed, but that dealer has nowhere to order stock from (see 7.2.4) |
| 3.2.3 | Approve a pending dealer | status becomes approved/active |
| 3.2.4 | Reject a pending dealer | status becomes rejected; code does not attribute |
| 3.2.5 | Toggle an approved dealer off and on | code stops and starts attributing |
| 3.2.6 | Filter the dealer list by distributor (`?dist=`) | only that distributor's dealers |
| 3.2.7 | Add a dealer beyond the distributor's dealer limit (`settings` → dealer limit) | refused with the limit named |
| 3.2.8 | Delete a dealer | removed; their existing attributed applications survive |

---

## 4. The application lifecycle — the spine of the system

Statuses, in the order they happen: `submitted → booking_pending → booking_review
→ docs_pending → confirm_pending → delivery_pending → delivery_review → complete`,
with `cancelled` and `rejected` sitting outside the line.

| # | Actor | Scenario | Expected |
|---|---|---|---|
| 4.1 | A | Open the row drawer for a new application | full submission shown, status **submitted** ("to approve") |
| 4.2 | A | Approve it (`submitted → booking_pending`) | payment email goes out with the booking number, amount and QR; `confirmed_at` set; the portal opens for that address |
| 4.3 | A | Reject it (`submitted → rejected`) with a reason | rejection email carrying the reason; no payment is ever asked for |
| 4.4 | A | Try any other status straight from the list (e.g. `submitted → complete`) | 409 — "Use the payment actions in the Details drawer" |
| 4.5 | A | Try a status that is not in the list at all | 422 "Unknown status." |
| 4.6 | A | Act on an id that does not exist | 404 |
| 4.7 | C | Sign in to the portal at `booking_pending` | timeline shows "Booking payment due", the amount and the upload form |
| 4.8 | C | Upload a booking receipt with reference + proof (`stage=booking`) | status → **booking_review**, applicant sees "verifying" |
| 4.9 | C | Try to upload the delivery payment while booking is unverified | refused — later stages are locked |
| 4.10 | A | Verify the booking payment (`action=accept`) | receipt number issued, receipt email sent, moves on to `docs_pending`/`confirm_pending` as the flow dictates |
| 4.11 | A | Reject the booking receipt with a reason | payment marked rejected, rejection email with the reason, applicant can upload again |
| 4.12 | A | Verify finance documents (`docs_verify`) | moves past `docs_pending` |
| 4.13 | A | Reject finance documents (`docs_reject`) with a reason | applicant emailed; the delivery payment stays shut; the application is **not** rejected outright |
| 4.14 | C | At `confirm_pending`, choose **continue** | moves to `delivery_pending`, delivery amount becomes payable |
| 4.15 | C | At `confirm_pending`, choose **cancel** | status **cancelled** — booking refund due, admin sees it as such |
| 4.16 | C | Upload the delivery receipt | status **delivery_review** |
| 4.17 | A | Verify the delivery payment | status **complete**, both receipts available |
| 4.18 | A | Send a reminder (`action=remind`) while a payment is due | reminder email sent |
| 4.19 | A | Send a reminder while a receipt is waiting to be checked | refused — "verify or reject it first" |
| 4.20 | A | Send a reminder when the balance is zero | refused |
| 4.21 | C | Download the receipt PDF for a verified payment | PDF served |
| 4.22 | C | Ask for a receipt id belonging to somebody else | refused — receipts are scoped to the signed-in address |
| 4.23 | A | Open the printable receipt / `receipt-pdf.php` from the admin | PDF renders with the receipt number |
| 4.24 | A | Every status change | recorded by `log_status_change` and visible in the row history |

---

## 5. Portal sign-in — one address, one code (C / D / X)

| # | Scenario | Expected |
|---|---|---|
| 5.1 | Request a code for an address that exists | code screen shown, six-digit code mailed to YOPmail |
| 5.2 | Request a code for an address that does not exist | the same screen — no enumeration |
| 5.3 | Enter the correct code | signed in |
| 5.4 | Enter a wrong code | error, stays on the code step |
| 5.5 | Enter an expired code | refused |
| 5.6 | Reuse a code that already signed in | refused |
| 5.7 | Too many wrong codes | throttled |
| 5.8 | "Use a different email address" (`action=restart`) | back to the email step, previous code void |
| 5.9 | Address registered in one role only | lands straight on that role's home |
| 5.10 | Address registered as more than one of applicant / dealer / distributor | the "Where to?" role picker; each card leads to its own home; "Sign out instead" works |
| 5.11 | Mail not configured on the server | the warning banner replaces the promise of a code |
| 5.12 | Sign out from any portal area | session gone, protected pages send you back |

---

## 6. Dealer portal (D)

| # | Scenario | Expected |
|---|---|---|
| 6.1 | Dealer home | own clients, own share link, own balance |
| 6.2 | `clients.php` | only clients attributed to this dealer; a rejected one is shown as rejected |
| 6.3 | Another dealer's client id | not reachable |
| 6.4 | `profile.php` → `save_profile` | bank/UPI/address changes stick; email and code are not editable |
| 6.5 | `stock.php` under a distributor → `order_stock` | order raised against that distributor, status pending |
| 6.6 | `stock.php` with no distributor above | told there is nowhere to order from — no form shown |
| 6.7 | Order more than the distributor holds | refused |
| 6.8 | `payouts.php` → `raise` a voucher | claim created at **with_distributor**, for the commission earned and not yet paid |
| 6.9 | Raise a second voucher while one is open | the open amount is not claimable twice |
| 6.10 | Raise a voucher with nothing owed | refused |
| 6.11 | Stock falls as clients apply through the dealer's code | ledger shows the deduction per sale |

---

## 7. Distributor portal (X)

| # | Scenario | Expected |
|---|---|---|
| 7.1.1 | Distributor home | dealers, sales, balance |
| 7.1.2 | `dealers.php` | own dealers only, with each one's state |
| 7.1.3 | `add-dealer.php` → `add_dealer` | dealer created under this distributor, pending |
| 7.1.4 | Add past the dealer limit | refused |
| 7.1.5 | `edit-dealer.php` → `save_dealer` | changes stick; a dealer belonging to someone else is not editable |
| 7.1.6 | `clients.php` | every client under this distributor, direct and through dealers |
| 7.2.1 | `stock.php` → `order_stock` from the office | order created, pending, awaits admin approval |
| 7.2.2 | Upload the payment proof for that order (`proof.php`) | proof attached to the order |
| 7.2.3 | Approve a dealer's stock order (`approve`) | units move from the distributor's shelf to the dealer's |
| 7.2.4 | Approve more than is on the shelf | refused |
| 7.2.5 | Reject a dealer's stock order (`reject`) | order rejected, no units move |
| 7.3.1 | `payouts.php` → `approve_dealer_voucher` | that dealer's voucher moves on toward R&F |
| 7.3.2 | `payouts.php` → `reject_dealer_voucher` | voucher sent back to the dealer, not destroyed |
| 7.3.3 | `bundle` several approved vouchers plus own commission | one bundle at **with_rf** carrying the children |
| 7.3.4 | Bundle with nothing approved | refused |
| 7.4 | `profile.php` → `save_profile` | bank details stick |

---

## 8. Admin: stock (A)

| # | Scenario | Expected |
|---|---|---|
| 8.1 | Stock page | every distributor order with its proof, and what each holds |
| 8.2 | Approve an order with a proof attached | units released to the distributor, ledger entry written |
| 8.3 | Reject an order | no units move, distributor told |
| 8.4 | Approve an order that has already been decided | refused |
| 8.5 | Act on an order that no longer exists | "That order no longer exists." |
| 8.6 | Change stock prices in `settings` (`stock_prices`) | new orders quote the new price; existing orders keep theirs |

---

## 9. Admin: commission and vouchers (A)

Voucher states: `with_distributor → bundled → with_rf → with_admin → funded`.

| # | Scenario | Expected |
|---|---|---|
| 9.1 | Commission page | bundles waiting, with what each is made of |
| 9.2 | `fund` a bundle | one transfer recorded against the whole bundle, state **funded**, each child marked |
| 9.3 | `reject` a bundle | the whole thing goes back and the dealers' vouchers return to their distributor |
| 9.4 | Act on a bundle that no longer exists | "That bundle no longer exists." |
| 9.5 | Change commission rates in `settings` (`commission`) | new sales use the new rate; already-earned commission is unchanged |
| 9.6 | Follow one sale from client to funded voucher | every hop appears in the voucher event history |

---

## 10. Admin: referrals (A)

| # | Scenario | Expected |
|---|---|---|
| 10.1 | Referrals page | one row per application that quoted a code, newest first |
| 10.2 | Mark a reward `sent` | referrer emailed that the money is on its way |
| 10.3 | Mark a reward `cancelled` | row closed, no mail promising money |
| 10.4 | Put a reward back to `pending` | row reopens |
| 10.5 | Change the reward amount in `settings` (`reward`) | new referrals use the new amount |
| 10.6 | A referral whose application is later rejected | not payable |

---

## 11. Admin: raffle (A + P)

| # | Scenario | Expected |
|---|---|---|
| 11.1 | `setup` the calendar | next reveal time set, countdown on the public page matches |
| 11.2 | `toggle` the raffle | public feed appears/disappears |
| 11.3 | Search by name, reference code or mobile (`raffle-search.php`) | the right person found |
| 11.4 | `add` a winner to a list | recorded against that draw |
| 11.5 | `remove` a winner | list edits at any point, before or after reveal |
| 11.6 | Look at the public page **before** the reveal time | list is private |
| 11.7 | Look **after** the reveal time | winners visible |
| 11.8 | Run `cron/voucher-run.php` | scheduled work happens without a browser session |

---

## 12. Admin: lists, exports, blog, settings (A)

| # | Scenario | Expected |
|---|---|---|
| 12.1 | Each list type (`stove`, `tuktuk`, `contact`, newsletter) | loads with its own status set |
| 12.2 | Filter by status | URL carries `status=`, only matching rows |
| 12.3 | Sort and paginate | page links keep the filter |
| 12.4 | Open a row drawer, then Escape | drawer opens with the full record and closes cleanly |
| 12.5 | Delete a row (`delete.php`) | gone from the list, and the confirm is required |
| 12.6 | Export `week` / `month` / `custom` range | file downloads, range respected |
| 12.7 | Export as `xlsx` and as `pdf` | both open, columns match the screen |
| 12.8 | Export dealers | dealer sheet downloads |
| 12.9 | Blog: `save` a `draft` | not visible publicly |
| 12.10 | Blog: `scheduled` with a future date | appears only after that time |
| 12.11 | Blog: `published` | live on `/blog.html` |
| 12.12 | Blog: `unpublished` | disappears from the public list |
| 12.13 | Blog: `drop_image`, then `delete` | image removed, post removed |
| 12.14 | Settings: `admin_save` a new admin | can sign in |
| 12.15 | Settings: `admin_toggle` | that admin can no longer sign in |
| 12.16 | Settings: `admin_delete` | account gone; you cannot delete the last one / yourself |
| 12.17 | Settings: `dealer_limit`, `rf`, `commission`, `reward`, `stock_prices` | each saves and takes effect where it is used |
| 12.18 | `error-log.php` | recent PHP errors readable |
| 12.19 | `file.php` for an upload | serves the file; a path outside the upload directory is refused |
| 12.20 | Admin at 125% and 150% Windows scaling | layout scales, no horizontal overflow, no overlapping controls |

---

## 13. Mail, end to end (all YOPmail)

Each of these should be checked by opening the inbox, not by trusting the UI.

| # | Trigger | Mail expected |
|---|---|---|
| 13.1 | Contact form submitted | acknowledgement to the sender |
| 13.2 | Newsletter subscribe | confirmation carrying a working unsubscribe token |
| 13.3 | Application approved (`→ booking_pending`) | payment email with booking number, amount, QR, portal link |
| 13.4 | Application rejected | rejection with the reason typed by the office |
| 13.5 | Payment verified | receipt email with the receipt number |
| 13.6 | Payment rejected | reason, and an invitation to upload again |
| 13.7 | Documents rejected | reason; delivery stays shut |
| 13.8 | Payment reminder | the amount still due |
| 13.9 | Portal sign-in requested | six-digit one-time code |
| 13.10 | Referral reward marked sent | the referrer told |
| 13.11 | Every mail | one-click unsubscribe header where it applies, and no broken links |

---

## 14. Negative and edge cases worth their own run

| # | Scenario | Expected |
|---|---|---|
| 14.1 | Two browsers act on the same application at once | the second action sees the new state and is refused, not silently applied |
| 14.2 | A client uploads a receipt for an application that was just cancelled | refused |
| 14.3 | Stock deducted at submission, then the application is rejected | the ledger shows what happened to that unit |
| 14.4 | A dealer is deleted while a voucher of theirs is in a bundle | the bundle stays whole |
| 14.5 | A distributor is toggled off with dealers below | dealer codes behave as the code decides — verify, do not assume |
| 14.6 | Very long free text (name, note, reject reason) | truncated to the column size, never a 500 |
| 14.7 | Unicode name / Gujarati text in a name field | stored and shown intact, in the admin, the mail and the PDF |
| 14.8 | Direct URL to another party's drawer, receipt, client or voucher | refused in every portal |

---

## Suggested run order

1. §1 public + forms (creates a contact, a newsletter address, nothing else)
2. §2 admin sign-in, §3 partners (creates distributor and dealer, gives you the codes)
3. §1.8 application quoting the dealer code (creates the client)
4. §4 the whole lifecycle, alternating admin and portal
5. §6 and §7 dealer and distributor portals (stock and vouchers)
6. §8–§10 admin stock, commission, referrals against what §6/§7 raised
7. §11–§12 raffle, lists, exports, blog, settings
8. §13 mail check for everything the run triggered
9. §14 negatives last — they leave data in odd states

Everything the run creates is named `QA …` and addressed at `@yopmail.com`, so a
cleanup is a search for `QA ` in each admin list.
