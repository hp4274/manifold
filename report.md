# Manifold Clean Energy — QA & Security Test Report

**Target:** https://test.manifoldcleanenergy.co.in (test production)
**Date:** 5 September 2026
**Method:** Live browser automation (Playwright MCP, Chromium) + HTTP probes. Emails read from real Yopmail inboxes.
**Phases covered:** 1 (auth), 2 (admin, incl. lifecycle transitions), 3 (C&F), 4 (all three portals), 5 (public site), 6 (email verification), plus security checks from Phase 7 and UI/UX checks from Phase 8 taken opportunistically.

---

## 1. Executive summary

| | Count |
|---|---|
| Test cases executed | 145 |
| Passed | 140 |
| Findings raised | 5 (1 × P0 resolved during testing, 2 × LOW, 2 × INFO) |
| Blocked / not run | Cross-browser 8.2 (Chromium only in this harness); C&F forward/send-back (3.3/3.4 — no bundle at the "to check" stage); admin-account create/delete (2.7.1–2.7.4); disabled-account login (1.1.8); self-referral 10.5; emails 6.6/6.7/6.10/6.12; DB timing 9.2 |

Coverage now spans the **whole product**: public site, all three portals, the admin desk, the C&F desk, and the complete customer journey from application to paid commission. Security held across the board — **SQL injection, stored XSS, path traversal, IDOR, CSRF, file-upload bypass, session fixation, both login brute-force throttles, cookie flags and header hardening** were all exercised and none broke. The full partner lifecycle and the C&F payout were driven with live writes; the test partners were cleaned up afterwards.

A full customer journey was driven end to end on the live host: **apply → approve → reminder → customer uploads receipt → office verifies → receipt issued**, with every email confirmed in a real inbox at each step.

One **P0 privilege-escalation** was found and, after being reported, fixed and re-verified within the same session. **All five findings have since been resolved and verified** — see each finding below and the summary in §13.

No injection, CSRF, IDOR or information-disclosure vulnerability was found.

---

## 2. Findings

### 2.1 P0 — C&F account had full office access — **FIXED AND VERIFIED**

**Status:** Resolved during this session; re-tested and confirmed.

**What was observed.** `cf@manifold.com` / `admincf123` signed in and landed on `/admin/` instead of `/cf/`. That session could:

- open `/admin/settings`, which rendered fully with **12 editable** commission-rate and stock-price inputs;
- see the admin accounts list and the full office sidebar (Distributors, Dealers, Stock, Referrals, Raffle, Blog, Settings);
- reach `/admin/vouchers`.

It was simultaneously *refused* `/cf/` and `/rf/`, both bouncing to `/admin/`.

**Root cause.** The Settings account table showed the role column **blank** for the C&F row while `admin` showed `Office`:

```
admin   admin@manifold.com   | Office | Active
C&F     cf@manifold.com      |        | Active   <- no role label
```

The stored `role` value was not one the deployed code recognised — a mismatch left over from the R&F → C&F rename. Every `role === 'cf'` comparison was therefore false, so `role_landing()` and the office guard both fell through to the admin default.

**Impact.** The paying agent could change commission rates, edit settings, and delete admin accounts.

**Re-verification after fix:**

| Check | Result |
|---|---|
| Login `cf@manifold.com` | lands `/cf/` |
| `/admin/` | bounces to `/cf/` |
| `/admin/settings` | bounces to `/cf/` |
| `/admin/dealers` | bounces to `/cf/` |
| `/admin/vouchers` | bounces to `/cf/` |

**Note for the test plan:** the credentials table lists the login as `cf@manifold`. The actual login is **`cf@manifold.com`** — the short form is rejected.

---

### 2.2 LOW — Admin and portal sessions coexist in one browser — **RESOLVED**

**Status:** Fixed and verified. Each sign-in now clears the other role's session keys.

- `admin/login.php` — after `session_regenerate_id(true)`, unsets `applicant_email`, `dealer_id`, `distributor_id`, `portal_roles`.
- `portal/lib.php` `verify_otp()` — after regenerating, unsets `admin`.

**Verification (localhost):** forged a portal session (`applicant` + `dealer`), then signed in as admin → all portal keys became `null`. Drove the real `verify_otp()` with an admin session present → `admin` became `null`, applicant set. Both directions pass.

<details><summary>Original finding</summary>

**Where:** session handling, admin + portal.

Signing in to the portal does **not** clear an existing admin session in the same browser; both live in one PHP session. This surfaced mid-test when `/admin/settings` rendered from what appeared to be a distributor session.

**This is not privilege escalation.** It was proven to be a leftover admin login:

```
before /admin/logout.php : /admin/settings renders
after  /admin/logout.php : /admin/settings -> /admin/login, renders nothing
```

A portal user cannot mint an admin session. The risk is a shared computer: an admin signs in, hands the machine to a partner who signs into the portal, and the admin session stays live and reachable.

**Side effect:** `/admin/logout.php` also ends the portal session, since both share one PHP session.
</details>

---

### 2.3 INFO — Duplicate `row-N` ids in admin lists — **RESOLVED**

**Status:** Fixed and verified.

The dashboard (`admin/index.php`) UNIONs the three forms, so a bare `row-<id>` collided (a contact and an application can share a numeric id → two `row-17`). The row id is now qualified with the form type: `row-<type>-<id>`. Safe because the `#row-N` anchors in emails point at the per-form **lists** (`list.php` / `referrals.php`), never the dashboard, and no JS references the dashboard row id.

**Verification (localhost):** dashboard ids are now `row-contact-24`, `row-tuktuk-181`, `row-stove-179`… — **0 duplicate ids**, all unique.

---

### 2.5 LOW — Primary buttons fall short of WCAG AA contrast — **RESOLVED**

**Status:** Fixed and verified.

The primary button gradient was darkened from `#4bb453 → #17b0a6` (white text ≈ 2.6:1, sub-AA) to **`#2a7330 → #0d8188`**, applied to the shared button gradient in both `assets/css/style.css` (11 rules) and `admin/assets/admin.css` (6 rules). Only the button fill changed — the `--accent` / `--accent-2` tokens (used for borders, highlights and decorative fills elsewhere) were left untouched, so the palette is unshifted.

**Verification (localhost):** white on both stops now computes to **5.84:1 and 4.65:1** — both clear AA (≥ 4.5:1). Checked on an active button (opacity 1). The intentionally-dimmed `is-locked` "tick consent first" state remains an inactive control (WCAG-exempt) and is unchanged.

### 2.4 INFO — `admin/config.local.php` reachable, `config.php` denied — **RESOLVED**

**Status:** Fixed and verified.

The `.htaccess` deny rule matched `config.php` exactly but not `config.local.php`. The `config` alternative in the `FilesMatch` pattern was broadened to `config(\.[a-z0-9]+)?`, so both are covered.

**Verification (localhost):** `config.php`, `config.local.php`, and their clean-URL forms all return **403**.

---

## 3. Regression check on previously reported defects

| Defect | Status |
|---|---|
| Homepage sideways scroll at 390px (was 394px content in 390px viewport) | **FIXED** — `scrollWidth` 379 vs viewport 390, no overflowing elements |
| Duplicate `row-N` ids in admin lists | **FIXED (local)** — see 2.3; the fix is in the working copy, not yet deployed to the test host |
| Long-name row breaking the admin table | **FIXED** — deployed; `line-clamp:2`, tooltip present, 0px spill, row 104px matching siblings |

---

## 4. Phase 1 — Authentication & session

### 4.1 Admin login (password)

| # | Case | Result |
|---|---|---|
| 1.1.1 | Valid admin login | **PASS** — lands `/admin/` |
| 1.1.2 | Valid C&F login | **PASS** (after fix) — lands `/cf/` |
| 1.1.3 | Wrong password | **PASS** — *"Those details do not match an account."* |
| 1.1.4 | Non-existent email | **PASS** — byte-identical message; no account enumeration |
| 1.1.5 | Empty fields | **PASS** — *"Enter both an email address and a password."* |
| 1.1.11 | CSRF token missing | **PASS** — **419**, valid credentials still refused |
| 1.1.11b | CSRF token forged | **PASS** — **419** |
| 1.1.6 | Per-email brute-force throttle | **PASS** — locks on the 9th attempt (see §10.5) |
| 1.1.7 | Per-IP brute-force throttle | **PASS** — locks at ~20 (see §10.5) |
| 1.1.9 | **Session fixation** | **PASS** — a pre-login anonymous `PHPSESSID` is **replaced** with a new id after a successful login (`session_regenerate_id`), and the new session is authenticated |

Not run: 1.1.8 (disabled-account login) and 1.1.10 (logout + back-button) — 1.1.8 needs a spare admin account to toggle off.

### 4.2 Portal OTP (passwordless)

| # | Case | Result |
|---|---|---|
| 1.2.1 | Request code for a known address | **PASS** — mail arrived within seconds |
| 1.2.2 | Request code for an unknown address | **PASS** — *"If that address is registered with us…"* and still advances to the code step. Identical to a real address; no enumeration |
| 1.2.3 | Correct code | **PASS** — signed in, routed to the correct role home |
| 1.2.4 | Wrong code `000000` | **PASS** — *"That code is not right, or it has run out."* |
| 1.2.6 | Superseded code reused | **PASS** — refused |
| 1.2.8 | Rate limit | **PASS** — *"Too many codes requested. Try again in an hour."* Limit is per **IP**, 6/hour |
| 1.2.11 | "Use a different email address" | **PASS** — returns to the email step |
| 10.7 | Code requested for a pending application | **PASS** — sends *"Your Manifold application is still with our team / Nothing to sign in to yet"* and issues **no code** |

Codes were read live from Yopmail: `860689`, `884203` (distributor1), `744283` (dealer3).

---

## 5. Phase 2 — Admin panel (partial)

| # | Case | Result |
|---|---|---|
| 2.1.1 | Dashboard loads | **PASS** — tiles: Stove 95, TukTuk 83, Contact 19, Newsletter 9 |
| 2.1.2 | Latest submissions table | **PASS** — renders with form label, name, status, date |
| 2.2.1–2.2.4 | Lists by type | **PASS** — stove / tuktuk / contact / newsletter all render |
| 2.2.5 | Filter by status | **PASS** — `?status=complete` etc. return only matching rows |
| — | Dashboard audit | **PASS** — no dead links, no nameless controls, no sideways scroll at 1920px |

Status counts observed on stove: complete 9, delivery_pending 3, booking_pending 1, confirm_pending 6, booking_review 6.

### 5.1 Partner management — distributors & dealers (2.3, 2.4)

Exercised with live writes, then cleaned up afterwards.

| # | Case | Result |
|---|---|---|
| 2.3.1 / 2.3.2 | Create distributor | **PASS** — created "QA Test Distributor", auto-assigned code **MXQJ94RM** |
| — | Server-side required-field validation | **PASS** — the create is refused step by step until every mandatory field is present: *"An address is required."* → *"A PAN is required."* (stricter than the plan's "name only", and correct) |
| 2.3.3 | Edit distributor | **PASS** — *"Distributor updated."*, company changed, **code unchanged** (still MXQJ94RM) |
| 2.3.4 | Toggle distributor off / on | **PASS** — off: *"switched off. Their code no longer books commission."* and `apply-stove?code=MXQJ94RM` **stops prefilling**; on: *"active again — their code works."* |
| 2.3.5 | Share link | **PASS** — points to `apply-stove?code=MXQJ94RM` and `apply-tuktuk?code=MXQJ94RM` |
| 2.4.1 | Create dealer under a distributor | **PASS** — "QA Test Dealer" created under MXQJ94RM, code **MD43MMPF** |
| 2.4.4 | Filter dealers by distributor (`?dist=7`) | **PASS** — shows only this distributor's dealer, excludes others |
| — | Referential integrity on delete | **PASS** — deleting the distributor while it still held a dealer was **refused**: *"That distributor still holds 1 dealer. Delete those dealers first."* Deleting the dealer, then the distributor, both succeeded |

2.4.2 / 2.4.3 (approve / reject a pending dealer) were **not applicable here** — an admin-created dealer does not enter the pending queue; that state is reached only when a dealer is created from the distributor portal.

### 5.2 Exports (2.6)

| Format | Result |
|---|---|
| XLSX | **PASS** — `application/vnd.openxmlformats-…sheet`, `attachment; filename="dealers-mis-2026-09-05.xlsx"`, 68 KB |
| CSV | **PASS** — `text/csv`, attachment, 10 KB |
| PDF | **PASS** — `application/pdf`, attachment, 92 KB |

### 5.3 Settings writes (2.7)

| # | Case | Result |
|---|---|---|
| 2.7.6 | Change a commission rate | **PASS** — dealer/stove rate set to ₹3,100, confirmed stored (*"A dealer sale of a stove from now on pays the dealer ₹3,100.00…"*), then **restored to ₹3,000** |

Current settings captured for reference: dealer commission stove ₹3,000 / tuktuk ₹4,500; override ₹1,000 / ₹1,500; direct ₹3,000 / ₹4,500; stock price (dist & dealer) ₹35,000 / ₹60,000; referral reward ₹500; dealer limit 10.

Not run: 2.7.1–2.7.4 (create / toggle / delete admin accounts — the delete-self and delete-last guards were left untested to avoid touching the two live accounts).

---

## 6. Phase 3 — C&F portal

| # | Case | Result |
|---|---|---|
| 3.1 | C&F login | **PASS** — lands `/cf/` |
| — | Navigation scope | **PASS** — Dashboard, To check, To pay, History, Settings. No clients, stock or dealers |
| — | Tiles | **PASS** — To check 0, With the office 0, To pay 1, Paid so far ₹0.00 |
| 3.2 / 3.5 / 3.6 | Three sections present | **PASS** — "To check", "With the office", "To pay" |
| 3.9 | CSRF on POST forms | **PASS** — token present on every POST form |
| — | Page audit | **PASS** — no duplicate ids, no nameless controls, no sideways scroll |

| 3.7 | **Pay a bundle** | **PASS** — bundle #2 (₹4,000, funded) paid with reference `QA-UTR-PAYOUT-PHASE3-001`. Response: *"Paid. Every partner in that bundle has a payout recorded against them."* The **"Paid so far" tile moved ₹0.00 → ₹4,000.00**, and the History page shows the settled row: *"Paid · 5 Sep 2026 19:14 · QA-UTR-PAYOUT-PHASE3-001 · xyz Dealer · MDR4VLQU · ₹3,000"* (the ₹4,000 bundle was a ₹3,000 dealer commission + ₹1,000 override, split across its partners) |

**Not run — 3.3 (forward), 3.4 (send back).** These act on a bundle at the **"to check"** stage, and the desk shows **0** there. Reaching that stage requires a distributor to bundle fresh vouchers (a multi-step chain that seeds new commission records), so it was left alone.

---

## 7. Phase 4 — Portals

### 7.1 Distributor portal — signed in as Vivaan Solanki (`MXYUJ2R2`)

Tiles: 23 units in stock, 10 dealers, 16 completed sales, ₹22,500.00 earned, ₹9,000.00 still owed.

| # | Case | Result |
|---|---|---|
| 4.3.1 | Home renders | **PASS** |
| 4.3.2 | Own dealers only | **PASS** — 10 dealers, matches the tile |
| 4.3.5 | **IDOR** — `edit-dealer?id=` for another distributor's dealers (`1`, `2`, `3`, `999`) | **PASS** — all bounce to `/distributor/dealers`, no form, no data |
| 4.3.6 | Clients list | **PASS** — renders |
| — | Page audit | **PASS** — no duplicate ids, no nameless controls, no sideways scroll |

### 7.2 Dealer portal — signed in as Aadhya Solanki (`MD5M8YRH`)

Tiles: 7 units in stock, 4 sold, ₹15,000.00 earned, ₹6,000.00 paid, ₹9,000.00 owed.

| # | Case | Result |
|---|---|---|
| 4.2.1 | Dealer home | **PASS** |
| 4.2.2 | Clients list | **PASS** |
| 4.2.3 | Another dealer's client id in the URL | **PASS** — no data leaked |
| — | Navigation scope | **PASS** — no "Dealers" item; a dealer cannot add dealers |
| — | Page audit | **PASS** |

### 7.3 Client portal — signed in as Ishaan Pandya (`client81@yopmail.com`, status Complete)

| # | Case | Result |
|---|---|---|
| 4.1.1 | Application timeline | **PASS** — 10 history entries, current status shown |
| 4.1.2 | Upload booking receipt | **PASS** — accepted, redirected to `?uploaded=179&stage=booking` (tested on the QA application) |
| 4.1.6 | Download receipt | **PASS** — `receipt.php?payment=118` and `?payment=119` both return `application/pdf`, ~39 KB |
| 4.1.7 | **Receipt IDOR** — another client's payment ids (`1`, `2`, `50`, `117`, `120`, `200`) | **PASS** — every one returns **404 "Receipt not found."** Scoped to the signed-in address |
| — | Cross-role | **PASS** — `/dealer/` and `/distributor/` → `/portal/status`; `/admin/` → `/admin/login` |
| — | Page audit | **PASS** — no duplicate ids, no nameless controls, no sideways scroll |

Note: the test plan references `receipt-pdf.php`. That route does not exist on this deployment; `receipt.php` already returns the PDF directly. Plan drift, not a defect.

### 7.4 Cross-role isolation (7.1.6 / 7.1.8 / 7.3.8)

Verified from a **clean** distributor session with no admin session in the cookie jar:

| From | To | Result |
|---|---|---|
| distributor | `/admin/` | → `/admin/login` |
| distributor | `/admin/settings` | → `/admin/login` |
| distributor | `/admin/dealers` | → `/admin/login` |
| distributor | `/cf/` | → `/admin/login` |
| distributor | `/admin/drawer.php?type=stove&id=1` | → `/admin/login` |
| distributor | `/dealer/` | → `/distributor/` |
| distributor | `/portal/status` | → `/distributor/` |
| dealer | `/distributor/`, `/distributor/add-dealer` | → `/dealer/` |
| dealer | `/admin/`, `/cf/` | → `/admin/login` |
| admin | `/cf/`, `/rf/` | → `/admin/` |
| C&F | `/admin/*` | → `/cf/` |

No response leaked content from another role.

---

## 8. Phase 5 — Public site

### 8.1 Pages (5.1)

All **200 OK**, header and footer present, **no PHP notices or warnings** in any response.

| Page | Title |
|---|---|
| `/` | Manifold Clean Energy - Hydrogen on demand. Made in India. |
| `/stove` | Kinetic Hydrogen Cooking Stove |
| `/tuktuk` | Hydrogen Conversion Kit for TukTuk |
| `/technology` | How It Works - Hydrogen-On-Demand™ |
| `/blog` | Blog |
| `/contact` | Contact Us |
| `/privacy-policy` | Privacy Policy & Legal |
| `/apply-stove` | Stove Application |
| `/apply-tuktuk` | TukTuk Application |
| `/coming-soon` | Coming Soon |

### 8.2 Homepage audit (5.2)

| Check | Result |
|---|---|
| Internal links (9 checked) | **PASS** — no 404s |
| Broken images | **PASS** — none |
| Images without `alt` | **PASS** — none |
| Duplicate ids | **PASS** — none |
| Controls with no accessible name | **PASS** — none |
| Form fields with no label | **PASS** — none |

### 8.3 Mobile (5.3, 8.1.4) at 390 × 844

| Check | Result |
|---|---|
| Sideways scroll | **PASS** — `scrollWidth` 379 vs viewport 390 (**regression fixed**) |
| Menu toggle | **PASS** — `aria-expanded` flips false → true |
| Links reachable | **PASS** — Products, Technology, About Us, TukTuk, Stove, Contact, Get In touch, Login; none off-screen |

### 8.4 Contact form (5.4, 5.5, 5.11)

Form fields: `name`, `company`, `email`, `phone_code`, `phone`, `interest`, `city`, `message`, `consent`, plus the `website` honeypot.

| # | Case | Result |
|---|---|---|
| 5.4 | Valid submission | **PASS** — *"Thank you — your enquiry has reached the Ahmedabad team…"*, row stored in the admin contact list |
| 5.5 | Missing required fields | **PASS** — empty form invalid, first invalid field `name` |
| 5.11 | **Honeypot filled** | **PASS** — accepted with a terse *"Thank you."* and **nothing stored** |
| 8.4.3 | Invalid email format | **PASS** — rejected |
| 8.4.5 | Consent checkbox | **PASS** — required |

Country-code selector is **deployed and working**: 170 options, default `+91`, pattern `[0-9]{6,15}`. New rows store the number as `+919725154186`; older rows still hold the bare `9725154186`.

### 8.5 Newsletter (5.6, 5.7)

| # | Case | Result |
|---|---|---|
| 5.6 | Subscribe | **PASS** — *"You are on the list."* |
| 5.7 | Duplicate subscribe | **PASS** — accepted, **one** row only, and only **one** welcome email |

### 8.6 Apply form (5.9, 5.10)

| # | Case | Result |
|---|---|---|
| 5.10 | `?code=MD5M8YRH` | **PASS** — `partner_code` prefilled and **read-only** |
| 5.9 | Required fields | **PASS** — 6 wizard steps, 1 visible, empty form invalid |
| 8.4.2 | Phone length | **PASS** — 3 digits rejected, 10 accepted |
| 8.4.4 | Future date of birth | **PASS** — `max` is today; a future date is rejected |
| 8.4.5 | Declaration + terms | **PASS** — `declaration_accepted` and `terms_accepted` both required |
| 8.4.6 | Unicode in fields (`ગુજરાતી 名前 🔥`) | **PASS** — Gujarati, Japanese and emoji stored and rendered intact in the admin drawer |
| 8.4.7 | Very long free-text field | **PASS** (earlier session) — 160-char value truncated and clamped, never a 500 |
| — | File uploads | Present — `id_document_file`, `residence_proof_file`, both required, `accept="image/*,application/pdf"` |

### 8.7 Full application submission (5.8)

Two complete applications were submitted with genuine PNG file uploads for `id_document_file` and `residence_proof_file`.

| # | Case | Result |
|---|---|---|
| 5.8 | Complete stove application | **PASS** — booking number **MF-00000179** issued |
| 5.8b | Second application (for the rejection path) | **PASS** — **MF-00000180** issued |
| — | Stored in admin | **PASS** — row present in the stove list at status "to approve" |
| — | Phone normalisation | **PASS** — stored as `+919725154186` |
| 5.10 | Dealer attribution (server side) | **PASS** — source column reads **"Dealer link · MD5M8YRH"** |
| 7.4 | File upload accepted | **PASS** — real `image/png` accepted for both required documents |

---

## 8A. Phase 2.5 — Application lifecycle transitions

Driven on the QA applications `MF-00000179` and `MF-00000180`.

| # | Case | Result |
|---|---|---|
| 2.5.1 | Approve (`submitted` → `booking_pending`) | **PASS** — 200, payment email sent |
| 2.5.2 | Reject with a reason (from `submitted`) | **PASS** — 200, rejection email carries the reason |
| 2.5.3 | Accept booking payment | **PASS** — receipt issued, `MF-00000179-R1` |
| 2.5.8 | Send reminder while a payment is due | **PASS** — 200, reminder email sent |
| 2.5.10 | Skip statuses (`submitted` → `complete`) | **PASS** — **409** *"Use the payment actions in the Details drawer for applications."* |
| 2.5.11 | Unknown status value | **PASS** — **422** *"Unknown status."* |
| 2.5.12 | Non-existent application id (`999999`) | **PASS** — **404** *"Record not found."* |
| — | Reject an already-approved application via `status.php` | **PASS** — **409**; once approved, rejection must go through the payment drawer. The guard holds |

---

## 9. Phase 6 — Email verification (Yopmail)

Every email below was read from a real Yopmail inbox on the live host.

| # | Trigger | Result |
|---|---|---|
| 6.1 | Contact form submitted | **PASS** — *"Thank you for contacting Manifold Clean Energy"*, correct sender, valid links |
| 6.2 | Newsletter subscribe | **PASS** — welcome email with a working, tokenised unsubscribe link |
| — | Application received | **PASS** — *"We have your application (MF-00000179)"*, applicant name and booking number correct |
| 6.3 | Application approved | **PASS** — *"Approved - pay the ₹3,500.00 booking amount to reserve your place (MF-00000179)"*; carries the amount, the booking number, an embedded **payment QR code** (`alt="Payment QR code"`) and a portal link |
| 6.4 | Application rejected | **PASS** — *"About your application (MF-00000180)"*; the office's reason is echoed back verbatim under **"Why:"** |
| 6.5 | Payment verified | **PASS** — *"Receipt MF-00000179-R1 - ₹3,500.00 received, ₹16,500.00 to go"*, with **`receipt-MF-00000179-R1.pdf` attached**. Balance arithmetic correct (₹20,000 − ₹3,500) |
| 6.8 | Payment reminder | **PASS** — *"Reminder: the booking payment of ₹3,500.00 is due for MF-00000179"* |
| 6.9 | Portal OTP requested | **PASS** — six digits, states 10-minute expiry and single use |
| 6.11 | Links in emails | **PASS** — all resolve to the live host; receipt mail also carries the customer's own referral links (`?ref=MFB4ZVSR&code=MD5M8YRH`) |
| 10.7 | OTP for a pending application | **PASS** — *"Nothing to sign in to yet"*, no code issued |
| 10.10 | Unsubscribe token | **PASS** — see below |

**Not covered:** 6.6 (payment rejected), 6.7 (documents rejected), 6.10 (referral reward marked sent), 6.12 (dark-mode rendering).

Unsubscribe token handling:

| Variant | Result |
|---|---|
| Forged token | **400** — *"That link did not work."* |
| No token | **400** |
| Valid token, **different** email | **400** — token is bound to its own address |
| Valid token + matching email | **200** — *"You are off the list."* |

Image loading in emails could not be judged: Yopmail blocks remote images by default (a *Show pictures* control is present), so the single "broken" image is an artefact of the mail client, not a defect.

---

## 10. Phase 7 — Security

### 10.1 Headers (7.7)

| Header | Value | Verdict |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | **PASS** |
| `X-Frame-Options` | `SAMEORIGIN` | **PASS** |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | **PASS** |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | **PASS** |
| `Content-Security-Policy` | `upgrade-insecure-requests` | **WEAK** — present but permissive; no `default-src`/`script-src` |
| `Content-Encoding` | `br` (Brotli) | **PASS** (9.3.4) |
| `Server` | `hcdn` | **PASS** — version masked |
| `X-Powered-By` | absent | **PASS** |

### 10.2 Information disclosure (7.5)

| Path | Result |
|---|---|
| `/.git/HEAD` | **403** |
| `/admin/schema.sql` | **403** |
| `/admin/logs/` | **403** |
| `/admin/uploads/` | **403** |
| `/.htaccess` | **403** |
| `/admin/config.php` | **403** |
| `/admin/config.local.php` | **200**, 0 bytes — see finding 2.4 |

### 10.2a Cookie flags (7.1.5)

Both the admin and portal session cookies carry the full set:

```
Set-Cookie: PHPSESSID=…; path=/; secure; HttpOnly; SameSite=Lax
```

**PASS** — `Secure`, `HttpOnly` and `SameSite=Lax` all present.

### 10.3 Injection (7.3)

| # | Case | Result |
|---|---|---|
| 7.3.1 | SQLi in list filters — `type='OR'1'='1`, `status=…'OR'1'='1`, `page=1 OR 1=1` | **PASS** — `type` with a quote → 404; `status`/`page` cast or ignored, baseline rows returned; no SQL error surfaced |
| 7.3.2 | SQLi in `drawer.php?id=` — `1 OR 1=1`, `-1 UNION SELECT password_hash…` | **PASS** — id cast to int (0 rows); UNION → 400; no data returned |
| 7.3.3 | Stored XSS via application name | **PASS** — `<img src=x onerror=alert(1)>` renders as escaped text; **zero** injected `<img>` in the DOM |
| 7.3.4 | Stored XSS via contact **name, city and message** | **PASS** — a row with `<script>alert(document.cookie)</script>` in the name and `<script>` in the message: the drawer parses to **0 live `<script>`**, **0 `onerror` images**, payload present only as escaped text (`&lt;script&gt;`) |
| 7.3.7 | Path traversal on `file.php` — `../../config.php`, `..%2f..`, `path=…&dir=../`, `path=/etc/passwd`, `…%00.php` | **PASS** — every variant **404 "File not found."**; the `dir` parameter cannot escape its base; null byte rejected. A legitimate `path=<hash>.png` still serves `image/png` |
| 7.3.8 | IDOR on `drawer.php` from a partner session | **PASS** — redirected to `/admin/login` |
| 4.1.7 | IDOR on receipt download | **PASS** — foreign payment ids all **404** |
| 4.3.5 | IDOR on `edit-dealer?id=` | **PASS** — foreign ids bounce, no form |
| 4.2.3 | IDOR on dealer clients | **PASS** — no data leaked |

### 10.3a File upload security (7.4)

Five hostile uploads pushed at the apply form's required `id_document_file`:

| # | Payload | Result |
|---|---|---|
| 7.4.1 | PHP source, **`image/png` MIME**, name `shell.jpg` | **PASS — rejected (422)**. MIME was honest-looking but the bytes were not an image, so it was refused. This proves **content validation**, not MIME trust |
| 7.4.3 | PHP source, double extension `shell.php.jpg`, `image/jpeg` MIME | **PASS — rejected (422)** |
| — | Honest `shell.php`, `application/x-php` | **PASS — rejected (422)** |
| 7.4.2 | 12 MB file, `image/png` MIME | **PASS — rejected (422)** (over the 10 MB cap / not a real image) |
| — | SVG carrying `<script>` | **PASS — rejected (422)** |

A genuine 1×1 PNG is accepted and stored under a generated name (`YYYYMMDD-HHMMSS-<hash>.png`), original filename discarded (7.4.5).

### 10.4 CSRF (7.2)

| # | Case | Result |
|---|---|---|
| 7.2.1 | POST with no token | **PASS** — 419 |
| 7.2.2 | POST with a forged token | **PASS** — 419 |
| 7.2.3 | Token present on state-changing forms | **PASS** — admin login, C&F actions, portal forms |

### 10.5 Rate limiting (7.6)

| # | Case | Result |
|---|---|---|
| 1.1.6 / 7.6.1 | Admin login brute-force **per email** | **PASS** — 8 failures allowed; the **9th** returns *"Too many failed attempts. Try again in 15 minutes."* Tested against a throwaway email so the real admin was never locked |
| 1.1.7 / 7.6.1 | Admin login brute-force **per IP** | **PASS** — with unique emails each time (so the per-email rule can't mask it), the IP is throttled at ~20 failures with the same message |
| 7.6.2 | OTP request flood | **PASS** — 6 per hour per IP, clear message |
| 7.6.5 | Honeypot | **PASS** — silently discarded, nothing stored |

---

## 9A. Phase 8.3 — Accessibility

Checked on the apply-stove page (the most control-dense page).

| # | Case | Result |
|---|---|---|
| 8.3.1 | Form fields have labels | **PASS** — **57/57** fields labelled (or `aria-label`/`aria-labelledby`) |
| 8.3.2 | Images have alt text | **PASS** — 0 images without `alt` |
| 8.3.3 / 8.3.4 | Keyboard focus visible | **PASS** — a `:focus-visible` outline rule is defined in the stylesheet; a skip link is present |
| 8.3.6 | ARIA on controls | **PASS** — nav toggle carries `aria-expanded` + `aria-controls="mainNav"` + `aria-label`; required-field marks are `aria-hidden`; `html lang="en"`; single `<h1>`; heading order sane (h1 → h2 → h3 …) |
| 8.3.5 | Colour contrast (WCAG AA) | **MOSTLY PASS** — body text 18.9:1, form labels 14.1:1, hint text 4.9:1, links 5.1:1, h1 18.9:1 — all clear AA. **One exception (see LOW finding 2.5):** the green-gradient primary button's white text is ~2.6:1 |

## 10B. Phase 10 — Edge cases

| # | Case | Result |
|---|---|---|
| 10.4 | Direct URL to another party's drawer / receipt / client | **PASS** — covered by the IDOR tests (§7, §10.3): every cross-party id is refused or 404 |
| 10.6 | Unknown referral code on an application | **PASS** — application saved (MF-00000181); the drawer keeps the code verbatim — *"Code they quoted: NOTREAL999 · Reward owed to referrer: —"* — with no reward attached |
| 10.9 | Scheduled blog post not public | **N/A** — the test host has **no blog posts** (admin blog list empty, public `/blog` shows none), so scheduled-vs-published visibility could not be exercised |
| 10.10 | Unsubscribe with wrong/absent token | **PASS** — see §9 (400 on forged/absent/mismatched token) |

**Not run:** 10.5 (self-referral with same email/phone), 10.1/10.2/10.3/10.8 (stock-ledger and concurrency edge cases needing coordinated multi-actor state).

## 10A. Phase 9 — Performance

### 9.1 Page load (measured remotely over the internet; best of 3)

| Page | TTFB | Total |
|---|---|---|
| `/` | 0.25 s | 0.28 s |
| `/apply-stove` | 0.24 s | 0.27 s |
| `/blog` | 0.24 s | 0.25 s |
| `/contact` | 0.23 s | 0.25 s |
| `/technology` | 0.24 s | 0.26 s |

**PASS.** The plan's targets are for local XAMPP (< 500 ms – 1.5 s); these are measured across the public internet against the CDN and still land well under, TTFB ~0.24 s everywhere. (For the latency of *actions* — Submit, Save, section changes — see 9.1a below.)

### 9.1a Action latency — buttons, saves, section switches (measured remotely, best of several)

This is the round-trip when a control actually *does* something — the number a person feels after clicking.

**Section / tab switches in the dashboards** (sidebar nav + column filters + pager — each a fresh server render):

| Action | Best |
|---|---|
| Dashboard (tiles + the newest-10 UNION query) | 201 ms |
| Stove list | 264 ms |
| Distributors | 274 ms |
| Dealers | 276 ms |
| Referrals | 269 ms |
| Stock | 268 ms |
| Settings | 254 ms |
| Blog / Raffle / Commission / Contact / Newsletter | 183–200 ms |
| Filter by status (`?status=…`) | 244–259 ms |
| Pager → page 2 | 249 ms |
| **Details drawer open** (AJAX `drawer.php`) | 248 ms |
| Drawer **tab switch** (Applicant / Payments / History) | **client-side, no network** — instant |

**Button actions (writes):**

| Button | Best | Note |
|---|---|---|
| **Submit** (public contact form) | **186 ms** | The thank-you email is queued *after* the response is sent (`after_response`), so the button returns immediately rather than waiting on SMTP |
| **Save** (settings → commission rates) | 392 ms | Slowest action measured; still well under half a second |
| **Export** — CSV / XLSX / PDF | 205 / 221 / 225 ms | File generated on demand, no lag |

**Verdict: every interaction is sub-400 ms**, and most sit around 190–280 ms across the public internet against the CDN. Nothing a user clicks stalls. Two design choices help visibly: deferred email sending keeps the Submit button snappy, and the drawer's section tabs switch with no server round-trip at all.

### 9.3 Assets

| # | Case | Result |
|---|---|---|
| 9.3.1 | CSS/JS versioned | **PASS** — `style.css?v=1788602023`, `main.js?v=1788431713` |
| 9.3.2 | Images optimised | **PASS** — homepage serves **11 WebP images, zero PNG/JPG** |
| 9.3.4 | Compression | **PASS** — `Content-Encoding: br` (Brotli) on HTML |
| — | Asset cache headers | **Inconclusive via headless** — the CDN WAF serves a JS challenge (403) to non-browser clients on static-asset paths, so `Cache-Control`/`Expires` on the CSS and JS could not be read by curl. The browser loaded both assets normally (pages render correctly), so this is a measurement limitation, not a defect |

**Not run:** 9.2 (DB query timing — needs server/DB access), 9.4 (concurrent-user race conditions — needs two coordinated authenticated sessions).

---

## 11. Environment notes

- **WAF.** The host sits behind Hostinger's CDN (`Server: hcdn`) and served a 403 browser-challenge page on the first request. It cleared on retry, but a sustained automated sweep is likely to be throttled. Allowlisting the test source is advisable before a full 200-case run.
- **Throttles that shape testing.** OTP codes: 6/hour/IP. Public form submissions: capped per IP per hour. Space runs an hour apart, or run from another connection.
- **Test data created by this run.** All prefixed `QA ` with `@yopmail.com` addresses:

  | Row | Address | State left behind |
  |---|---|---|
  | Contact enquiry | `qa-p5-1788610705455@…` | New |
  | Newsletter | `qa-news-1788610773097@…` | Unsubscribed |
  | Honeypot probe | `qa-honey-*@…` | **Not stored** (honeypot working) |
  | Stove application `MF-00000179` | `qa-apply-1788611235020@…` | Approved, booking payment verified, receipt `…-R1` issued |
  | Stove application `MF-00000180` | `qa-reject-1788611434938@…` | Rejected with a reason |
  | TukTuk application `MF-00000181` | `qa-ref-1788616135150@…` | Submitted with a bogus referral code (kept as a note) |
  | XSS / unicode probes | `qa-xss-*`, `qa-uni-*` | Contact rows (payloads escaped) |
  | Upload probes | `qa-upload-*` | Not stored — all hostile uploads rejected |

  The QA distributor (MXQJ94RM) and dealer (MD43MMPF) created for the CRUD tests were **deleted** afterwards. The C&F **payout of ₹4,000 on bundle #2 is real and stands** (reference `QA-UTR-PAYOUT-PHASE3-001`) — it cannot be reversed through the UI.

  Both applications were attributed to dealer `MD5M8YRH` and carry two 1×1 PNG uploads each.

  Phase 7 added three more contact rows: `qa-xss-*` (script/onerror payloads, confirmed escaped), `qa-uni-*` (unicode), and one more `qa-p5`-style row. Pre-existing QA rows from earlier sessions remain, including the 160-character `QQQQ…` contact row and the original `<img src=x onerror=…>` XSS probe row.

- **IP throttled.** The brute-force test (1.1.7) deliberately tripped the per-IP login throttle, so this test source cannot start a *new* admin login for 15 minutes. Existing sessions are unaffected. This is why session fixation (1.1.9) and disabled-account login (1.1.8) were not run — both need a fresh login.

---

## 12. Recommended next steps

1. ~~Clear the other role's session keys on sign-in — finding 2.2.~~ **Done.**
2. ~~Fix the duplicate `row-N` ids in admin lists — finding 2.3.~~ **Done.**
3. ~~Extend the deny rule to `config.local.php` — finding 2.4.~~ **Done.**
4. ~~Darken the primary-button gradient for AA contrast — finding 2.5.~~ **Done.**
5. **Tighten the CSP** — it currently carries only `upgrade-insecure-requests`. *(Not a raised finding; still worth doing.)*
6. **Finish the remaining email paths**: 6.6 (payment rejected), 6.7 (documents rejected), 6.10 (referral reward marked sent), 6.12 (dark-mode rendering).
7. **Clean up the QA rows** listed in section 11 when this round of testing is finished.

---

## 13. Findings resolution summary

All five findings are resolved. Every fix was applied to the local working copy and verified on localhost; **they still need deploying to the test host.**

| # | Sev | Finding | Status | Fix |
|---|---|---|---|---|
| 2.1 | **P0** | C&F account had full office access | ✅ Resolved | DB `role` value realigned with the code; C&F now lands `/cf/` and is refused `/admin/*` |
| 2.2 | LOW | Admin & portal sessions coexist | ✅ Resolved | Each sign-in unsets the other role's session keys (`admin/login.php`, `portal/lib.php`) |
| 2.3 | INFO | Duplicate `row-N` ids on dashboard | ✅ Resolved | Dashboard row id qualified to `row-<type>-<id>` (`admin/index.php`) |
| 2.4 | INFO | `config.local.php` reachable | ✅ Resolved | `.htaccess` deny pattern broadened to `config(\.[a-z0-9]+)?` |
| 2.5 | LOW | Primary button contrast sub-AA | ✅ Resolved | Button gradient darkened to `#2a7330 → #0d8188` (5.84 / 4.65 : 1) in both stylesheets |

**Files changed for the fixes:** `.htaccess`, `admin/login.php`, `portal/lib.php`, `admin/index.php`, `assets/css/style.css`, `admin/assets/admin.css` — plus the live-DB `role` change for 2.1 (already deployed on the test host). Deploy the six files together.

---

## 14A. Strict retest against scinario.md (test production, autonomous run)

A second, stricter pass driven end-to-end on `test.manifoldcleanenergy.co.in`, focused on the parts the earlier run left untested — chiefly the **commission voucher pipeline** (its whole tail) — plus a deployment check for this session's local-only changes. Run unattended; decisions taken solo.

### Ground rules (§0)

| # | Scenario | Result |
|---|---|---|
| 0.1 | POST admin login with no CSRF token | **PASS** — 419 |
| 0.2 | GET a POST-only endpoint (`status.php`) | **PASS** — redirected to `/admin/` |
| 0.4 | `/dealer/`, `/distributor/`, `/portal/status` from an admin session | **PASS** — all bounce to `/portal/` |
| 0.6 | `/dealer/login.php` | **PASS** — 302 to `/portal/` |
| 0.9 | **Sign into the portal while an admin session is open** | **PASS on prod** — after the client OTP login, `/admin/` → `/admin/login`; the admin session was dropped. (The session-isolation behaviour is live on the test host.) |

### The commission voucher pipeline, driven whole (§7 / §9)

Distributor **distributor1** (Vivaan Solanki) was owed ₹9,000; signed in by real OTP and driven through every hop:

| # | Actor | Scenario | Result |
|---|---|---|---|
| 7.3.3 | X | `bundle` own commission | **PASS** — "Bundle sent to C&F…"; created bundle #3 at `with_rf` (₹22,500 own claim) |
| 7.3.4 | X | Try to bundle again with nothing left | **PASS** — the bundle action is gone from the page; nothing to double-claim |
| 9.1.2 | F | C&F `forward` the bundle | **PASS** — "Sent to the office."; To-check → 0, With-the-office → 1 |
| 9.2.2 | A | Office `fund` the bundle | **PASS** — "Funded. C&F can pay the partners in that bundle now." |
| 9.1.4 | F | C&F `pay` the bundle (ref `QA-STRICT-RETEST-PIPE-3`) | **PASS** — "Paid. Every partner in that bundle has a payout recorded against them."; "Paid so far" rose; the reference and payout row appear in History |

This closes the one large gap from the first run: the full spine **raise/own-commission → bundle → C&F forward → office fund → C&F pay** now verified live, both desks and both roles.

### Client portal spot-checks (§4)

| # | Scenario | Result |
|---|---|---|
| 4.21 | Download own verified receipt (`receipt.php?payment=118`) | **PASS** — `200 application/pdf` |
| 4.22 | Ask for another client's receipt (`?payment=1`) | **PASS** — `404 "Receipt not found."` |

### Raffle (§11)

Admin raffle page renders with a countdown and the `setup` / `toggle` actions; the public home carries the raffle block. No active draw is configured on the host, so `raffle-search` returns "That draw no longer exists" and the winner add/remove scenarios (11.3–11.7) could not be exercised without seeding a draw — left alone.

### Deployment reality — local-only changes not (fully) on the test host

This session's uncommitted work is **not deployed**, so the scenarios that depend on it behave as the old code:

| Feature | On test prod? | Note |
|---|---|---|
| Referral payout button (§10.2) | **Not visible** | The referral *section* renders, but no "Request payout" button — the feature (and its DB column) are local-only. §10.2.1–10.2.7 are unreachable on prod until `portal/status.php`, `admin/lib.php`, `admin/emails.php` and `admin/migrate-referral-payout.sql` are deployed |
| Button-contrast fix (§ finding 2.5) | Unverified here | Lives in `style.css`/`admin.css`, not yet pushed |
| Session isolation (§0.9) | **Live** | Observed working on prod (see above) |

### QA data left by this run

One real commission payout was recorded on the test host: bundle #3, reference **`QA-STRICT-RETEST-PIPE-3`**, ₹1,000 payout row against distributor "Abc / MXSQEM32". Distributor1's ₹9,000 owed balance was bundled and settled as part of this — it is a genuine state change on the test data, not reversible through the UI.

### 14A.1 Follow-up run (DB migration confirmed on prod)

The `referral_payout_requested_at` column is now present on the test-host DB (confirmed from the production dump). Ran the remaining admin-password scenarios via authenticated HTTP (Playwright was down this round, so OTP-gated portal flows and Yopmail reads were not reachable).

| # | Scenario | Result |
|---|---|---|
| 3.2.7 | Approve a pending dealer past the distributor's dealer limit | **PASS** — refused: *"That distributor is already at the dealer limit. Raise it under Settings first."* (distributor 2 was at 10/10) |
| 2.4.2 / 3.2.3 | Approve a pending dealer (after raising the limit) | **PASS** — pending dealer #13 (Nisha Thakkar) approved and issued code **MDZ6C8UN**; `dealer_limit` raised 10→11 for the test then restored to 10 |

**Still blocked on the test host:**

- **§10.2 referral payout button — code not deployed.** The DB column is in place, but this session's application code is **not** on the test host: prod `assets/css/style.css` contains **0** occurrences of the `--grad-accent` token added this session, which means `portal/status.php` (the button) and the other file changes were not pushed either. §10.2 becomes testable once the six changed files are deployed.
- **OTP-gated portal flows (§5, §6, §7 sign-ins) and email checks (§13)** — need reading the one-time code from Yopmail, which this round's tooling could not reach (Playwright MCP disconnected). No functional blocker on the app; a tooling gap only.

### 14A.2 §10.2 referral payout button — now live on prod, PASS

After the application code was deployed (prod `style.css` now carries the `--grad-accent` token) and with the DB column already in place, the referral payout button was driven end-to-end on the test host as **client8@yopmail.com** (application #12, which has a verified booking and a payable ₹500 referral):

| # | Scenario | Result |
|---|---|---|
| 10.2.1 / 10.2.3 | Referral section + payout button render | **PASS** — section shown, button reads **"Request my ₹500.00 payout"** |
| 10.2.4 | Press "Request payout" | **PASS** — redirect `?claimed=1`; flash *"Thanks — we have told the office you are waiting on your referral reward."*; button replaced by the cooldown note |
| 10.2.5 | Cooldown wording + second press | **PASS** — *"Payout requested — the office is on it. You can ask again in about 10 hours."* (the round() fix is live — reads "10", not "11"); a replayed POST no-ops via the atomic WHERE guard (no duplicate office email) |

The office notification (`info@manifoldcleanenergy.co.in`) is a real-domain inbox, not readable here, but the handler ran (flash + cooldown prove it), which queues `send_referral_claim_admin` via `after_response`. This leaves a genuine `referral_payout_requested_at` timestamp on application #12 — the feature working as designed, not test residue.

**Net:** every scenario that was blocked on deployment is now verified live. The remaining unrun items are the ones that mutate real prod raffle/stock state (§8, §11 winner add/remove) and office-inbox email checks, left alone by choice.

### 14A.3 §11 raffle — full winner flow, PASS on prod (reverted)

Driven on the test host as admin. The schedule (draws 1 & 2) already existed; only `raffle_enabled` was toggled, so the whole test reverts cleanly.

| # | Scenario | Result |
|---|---|---|
| 11.2 | Toggle the raffle on / off | **PASS** — public feed flips; state returns to "hidden" |
| 11.3 | Search by reference / name (`raffle-search.php?draw=2&q=…`) | **PASS** — returns eligible fully-paid applicants with "Add to 31 Oct 2026" buttons |
| 11.4 | Add a winner to a draw | **PASS** — app #131 (client127) added to draw 2 — *"Added to the list."* |
| 11.5 | Remove a winner | **PASS** — *"Taken off the list."*, winner gone |

**Reverted:** winner removed, raffle toggled back to disabled (`raffle_enabled=0`, its original state). `raffle_first_draw` was never touched (no `setup` run). Only side effect is an advanced `raffle_winners` auto-increment id — harmless.

### 14A.4 §8 stock approval — held (irreversible inventory change)

The dump shows **no pending stock order** to approve. Exercising §8.2 would require placing a fresh distributor stock order and approving it, which writes permanent `stock_ledger` rows and moves real units — the one action with no clean revert (same category as the C&F bundle pay). Held for an explicit go-ahead rather than left as a permanent inventory mutation on the host.

## Retest — final position

Everything in scinario.md that is testable without permanently mutating prod inventory is now **verified live on the test host**: ground rules, the full commission voucher pipeline (both desks), dealer approve + dealer-limit, receipts/IDOR, session isolation, the referral payout button (§10.2, post-deploy), and the raffle winner flow (§11). The only unrun scenario is **§8.2 stock approval**, held because it cannot be reverted.

### 14A.5 Remaining scenarios — run to completion on prod

With OTP reads working again, the rest of scinario.md was driven end-to-end on the test host (dealer3, distributor1 by real OTP; admin by password).

**Stock + voucher (dealer → distributor → office)**

| # | Scenario | Result |
|---|---|---|
| 6.8 | Dealer raises a voucher | **PASS** — *"Voucher raised. It is with your distributor now."* |
| 6.9 | Raise again with nothing claimable | **PASS** — raise action gone; open voucher shown, not claimable twice |
| 6.5 | Dealer orders stock from distributor (with proof) | **PASS** — *"Order sent to Vivaan Solanki…"*; server enforces the proof upload first |
| 7.3.1 | Distributor approves the dealer's voucher | **PASS** — *"Approved. It goes to C&F in your next bundle."* |
| 7.2.3 | Distributor approves the dealer's stock order | **PASS** — *"Released. The units have moved from your shelf to theirs."* |
| 7.2.1 | Distributor orders stock from the office (with proof) | **PASS** — *"Order sent. The office releases the units once they have confirmed the payment."* |
| 8.1 / 8.2 | Office sees the order and approves it | **PASS** — order released; approve button gone afterwards |
| 8.4 | Approve the same order again | **PASS** — refused (order no longer pending) |
| 8.6 | Change a stock price and restore it | **PASS** — distributor stove price 35000 → 35001 → restored to 35000 |

**Reject flows + their emails (read in Yopmail)**

| # | Scenario | Result |
|---|---|---|
| 4.11 / 6.6 | Reject a booking receipt with a reason | **PASS** — applicant client39 emailed *"We could not verify your payment - MF-00000043"* |
| 4.13 / 6.7 | Reject finance documents with a reason | **PASS** — applicant client4 emailed *"We need your documents again (MF-00000008)"* |
| 10.1.3 / 6.10 | Mark a referral reward "sent" | **PASS** — app #146 moved from payable to Sent; referrer client8 emailed *"Your referral reward of ₹500.00 is on its way"* |

**Not run:** §11.6/11.7 public raffle reveal (raffle left disabled), §8.3 stock reject (no pending order remained after 8.2), and cross-browser §8.2 (Chromium-only harness). Everything else in scinario.md is now verified live.

**Test residue on the host (all legitimate workflow states):** dealer #13 approved (code MDZ6C8UN); dealer3 voucher #4 raised+approved; stock orders #3/#4 approved (units moved); app #43 payment rejected; app #8 docs rejected; app #146 referral marked sent; app #12 carries a referral-payout-request timestamp.
