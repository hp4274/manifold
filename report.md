# Manifold Clean Energy — UI & Functional Audit

**Target:** `https://test.manifoldcleanenergy.co.in` (live test-production)
**Date:** 6 September 2026
**Method:** Playwright (real Chrome, passes the Cloudflare challenge that 403s headless), three viewports — Mobile 390×844, Tablet 768×1024, Desktop 1440×900 — with a DOM audit probe, console/network capture, and scripted interaction stress. Candidate findings were then adversarially re-verified against WCAG 2.2 and the source, and every one that survived automated flagging was checked by hand.

---

## 1. Executive summary

**No Critical, High, or Medium UI or functional defects were found**, across the public site, the admin console, the C&F desk, and all three OTP partner portals — **≈41 distinct URLs, ~123 page-renders**, plus interaction flows.

- **0 console errors, 0 uncaught exceptions, 0 failed network requests (4xx/requestfailed)** on any audited page at any viewport.
- **0 horizontal-scroll / overflow, 0 fixed-chrome occlusion, 0 broken images** anywhere.
- Every interactive component exercised (navigation, dropdown, raffle modal, forms, admin Details drawer, column filters, confirm dialogs, OTP login) behaved correctly — nothing froze, mis-closed, or failed to load.
- **Every candidate finding the automated probe raised turned out to be a false positive or a deliberate, standards-conformant design choice.** The only items with any residual severity are optional **WCAG AAA** touch-target enhancements (Low), none of which are AA conformance failures.
- The mobile UI bug the user reported earlier (admin dashboard "Actions" column icons not aligned under their header) **does not reproduce on current test-production** — the header is right-aligned over its icons (measured 28px, visually aligned).

The site is in strong shape. The remainder of this report documents coverage, the investigated-and-dismissed findings (so a reader can see they were checked, not missed), and the parts of a "strict, comprehensive" audit that a browser DOM sweep cannot reach — recommended as the next pass.

---

## 2. Scope & pages tested

Legend: **✓** audited (probe + console/net across 3 viewports); **⤢** interaction-stressed.

### Public marketing (no auth)
| Page | Result |
|---|---|
| `/` (home) ⤢ | ✓ clean |
| `/stove` | ✓ clean |
| `/tuktuk` | ✓ clean |
| `/technology` | ✓ clean |
| `/contact` ⤢ | ✓ clean |
| `/blog` | ✓ clean (0 published posts → intended empty state, no cards, no error) |
| `/apply-stove` ⤢ | ✓ clean |
| `/apply-tuktuk` ⤢ | ✓ clean |
| `/privacy-policy` | ✓ clean |
| `/coming-soon` | ✓ clean |
| `/portal/` (login) | ✓ clean |
| `/admin/login` | ✓ clean |

### Admin console — `admin@manifold.com` ⤢
`/admin/` · `/admin/list?type=stove|tuktuk|contact|newsletter` · `/admin/dealers` · `/admin/distributors` · `/admin/stock` · `/admin/vouchers` · `/admin/referrals` · `/admin/raffle` · `/admin/blog` · `/admin/settings` · `/admin/error-log` — **14 URLs, all ✓ clean**. Details drawer, column filter, and confirm-dialog interaction verified.

### C&F desk — `cf@manifold.com`
`/cf/` · `/cf/history` · `/cf/settings` — **3 pages, all ✓ clean**.

### OTP partner portals (email + one-time code via yopmail)
| Portal | Pages | Result |
|---|---|---|
| Dealer (`dealer1@`) ⤢ | `/dealer/` `/clients` `/stock` `/payouts` `/profile` | ✓ clean |
| Distributor (`distributor1@`) | `/distributor/` `/clients` `/dealers` `/add-dealer` `/stock` `/payouts` `/profile` | ✓ clean |
| Applicant (`client153@` complete, `client152@` booking-pending) ⤢ | `/portal/status` (both states) | ✓ clean |

**Endpoints excluded from the visual audit** (handlers/downloads/redirects, no rendered UI): `submit`, `status`, `payment`, `delete`, `drawer`, `file`, `receipt(-pdf)`, `export`, `logout`, `prefill`, `session`, `raffle-search`, `unsubscribe`, `seeder`.

---

## 3. Findings

### 3.1 Critical / High / Medium
**None.**

### 3.2 Low — optional accessibility enhancements (WCAG AAA only; not AA failures)
These are genuine sub-44px touch targets. WCAG 2.2 **SC 2.5.5 (Enhanced, AAA)** wants 44×44; the enforceable **SC 2.5.8 (Minimum, AA)** wants 24×24 with inline/spacing exceptions. Every item below **passes AA** and misses only AAA.

| ID | Where | Element | Measured | Note |
|---|---|---|---|---|
| L1 | stove/tuktuk/apply breadcrumbs | breadcrumb links | ~20px tall | The one item under the 24px AA floor by height; still conforms via the inline-text + 10px-gap spacing exception. A 2px vertical padding would reach 24px. Closest thing to a real issue. |
| L2 | all partner portals + admin | sidebar nav links | 26px (390) / 30px (768) tall | Full-width rows, comfortably above 24px AA; AAA-only. |
| L3 | dealer portal | `Copy link` / `Add a client` / menu items | 28–30px tall | AA-conformant; AAA-only. |
| L4 | apply-stove | toast `Dismiss` button | 26×26 | Meets 24px AA; AAA-only. |
| L5 | admin/login, cf/settings, error-log | primary/number buttons & inputs | height 37–39 | Clear the 24px AA floor; miss AAA 44px by ≤7px. |

No Low item blocks any task; they are batched here as an optional "raise small controls to 44px on touch" enhancement.

### 3.3 Investigated and dismissed (checked, not defects)
Recorded so a reviewer can see these were verified rather than overlooked.

| Candidate | Verdict | Why |
|---|---|---|
| **Apply-form phone accepts letters** (`#mobile_number` `pattern=null`, `maxlength=11`, `"abc"` passed `checkValidity()`) | **False positive** | `assets/js/apply.js` `shapeNumber()` intentionally removes the static `pattern` and validates per-country on every **input event** via `setCustomValidity()`, masking the value (strips non-digits). Reproduced with **real typed input**: `"abc"`→`""`→blocked (required); `"12345"`→"A number for +91 is 10 digits. This one has 5."; `"9876543210"`→valid. The false pass occurred only because the probe assigned `.value` without firing an input event — a path no user or browser takes. The static `pattern="[0-9]{10}"` no-JS fallback is also present in the live HTML. |
| Contact `#consent` checkbox 24×24 | False positive | Wrapped in `<label for="consent">` spanning the whole consent sentence — the entire row is the hit target. |
| Apply file inputs 1×1 (`#id_document_file`, `#residence_proof_file`) | False positive | Styled-label upload pattern: the visible `<label class="upload">` is the target; the input is intentionally shrunk. |
| Skip-link "Skip to content" (1×1 / 104×26) | False positive | `position:absolute; left:-9999px`, surfaced only on `:focus` — a keyboard affordance, never a pointer target. |
| Admin `modal-x__backdrop` ×2 overlapping (dealers/distributors/settings) | False positive | Both backdrops are `pointer-events:none`; `elementFromPoint(center)` returns the table cell. Inert closed-modal scaffolding — does not block clicks or dim the page. |
| `/admin/referrals` "ACTION" header 50px off its buttons | False positive | The header sits directly above the "UPI / UTR reference" input that **leads** each action cell (screenshot evidence). It correctly labels the column; the 50px was header-vs-buttons, ignoring the leading input. |
| 11px uppercase eyebrow / stat micro-labels (admin, dealer, distributor, cf) | By design | `.eyebrow` / `.sidebar__label` use `var(--t-micro)` = `clamp(11px, 0.13vw+9.9px, 12px)` — relative, honours zoom (SC 1.4.4 met). WCAG sets no absolute minimum font size; these are decorative labels above real headings. |

---

## 4. Interaction & functional results

| Area | Test | Result |
|---|---|---|
| Home nav (mobile) | Hamburger open/close; aria-expanded toggles | PASS |
| Home nav (mobile) | "Apply Now" `<details>` opens on a **single** tap | PASS — earlier double-tap bug is fixed on prod |
| Home nav (mobile) | Duplicate close button in panel | None (0) — earlier duplicate-close bug fixed on prod |
| Newsletter form | Empty + invalid-email submit | Blocked by native validation, correct message |
| Contact form | Empty submit → first invalid (`fullName`) focused; bad email; consent required; honeypot hidden | PASS; no row inserted |
| Apply forms | Empty submit blocked; email `pattern`, pin `[0-9]{6}`, per-country phone validation; file `accept=image/*,application/pdf` required | PASS |
| Raffle modal | — | Disabled in admin (`raffle.php` → `"enabled":false`); `main.js` correctly hides the trigger. Not reachable by design; not exercised. |
| Applicant upload (booking-pending) | Empty upload blocked by required file | PASS |
| OTP login ×4 (dealer/distributor/2×applicant) | Email → code → dashboard; address decides role | PASS, no console errors |
| Admin Details drawer | Open (body scroll-locked) → Escape closes | PASS |
| Admin column filter | Click filter → rows 10→5 | PASS |
| Admin confirm dialog | Submit a `data-confirm` action → cancel | No mutation (URL unchanged) |
| Login form (bad creds) | — | Correct error "Those details do not match an account."; CSRF field present |

---

## 5. Status of the previously-reported bug
The admin dashboard "Actions" column misalignment reported earlier **is resolved on current test-production**. Measured `/admin/` — the ACTIONS `<th>` is `text-align:right`, header-center 1198px vs icon-cluster-center 1171px (28px), and the check/X/trash icons sit under the header (screenshot evidence in `.playwright-mcp/audit/admin-dashboard-desktop.png`). Dealers/distributors tables likewise aligned (gap ≤12px).

---

## 6. Coverage gaps & limitations (recommended next pass)
A browser DOM sweep is thorough for layout, console health, and interaction wiring, but a *complete* audit still needs these — none were possible or in-scope this pass:

**Higher value**
- **Mutation / write flows** were deliberately not executed anywhere (to avoid altering live data): distributor approve/reject dealer, bundle voucher, approve stock order, place office order; admin CRUD; referral "Mark sent". Their controls render without error; downstream logic was validated in the prior production test pass.
- **Authorization boundaries** (cross-role / object-level): e.g. can a dealer session reach `/distributor/*` or `/admin/*`; can an applicant hit privileged endpoints; are booking references enumerable. Not tested.
- **Form end-to-end success + server-side validation**: only native/empty validation was checked; no valid submission was completed (would insert DB rows). Server-side rules, success/redirect states, and real **file-upload acceptance** are unverified.

**Medium**
- **Real colour-contrast ratios** (the probe measured font size, not contrast) — worth computing for the 11px low-emphasis labels and pills.
- **Keyboard-only navigation & focus management**: tab order, visible focus rings, skip-link jump, focus-trap/return in the hamburger panel and Details drawer.
- **Screen-reader / ARIA semantics** beyond `aria-expanded` / `role=dialog`: landmark & heading order, label associations, table header scope, live-region announcements.
- **OTP / auth negative paths**: wrong / expired / reused code, resend, rate-limiting, unknown-email behaviour; per-email brute-force lockout and CSRF enforcement (not re-exercised to avoid locking the real accounts).
- **Unreached UI states**: raffle modal (raffle disabled on prod), blog post drawer/card grid (0 published posts), distributor add-dealer form in its *room-available* state (test account is at its 10/10 dealer cap).
- **Build-vs-source drift**: the working tree carries changes not on test-prod; a systematic deployed-vs-source diff is advisable.

**Lower**
- Cross-browser / real-device (Safari-iOS, Firefox); true touch vs geometric target measurement.
- Performance / Core Web Vitals (LCP, CLS, page weight, slow-network).
- Intermediate breakpoints (~480 / 600 / 1024 / 1280 / ultra-wide) beyond the three sampled.
- Anchor-target integrity (crawl internal links for 404s).

---

## 7. Recommendations
1. **Optional (AAA polish):** nudge the sub-24px breadcrumb link height (L1) to ≥24px, and, if aiming for AAA, raise small controls (nav rows, toast close, form buttons L2–L5) to 44px on touch. None are conformance blockers.
2. **Next audit pass:** run the "Higher value" gaps above in a disposable/seeded environment where mutations and real uploads are safe, plus a contrast + keyboard + screen-reader pass.
3. **No code fix is required for any item in §3** — the apply-phone, checkbox, file-input, skip-link, backdrop, and referrals-header flags were all confirmed non-issues.

*Evidence artifacts (probe JSON, per-area notes, screenshots) are under `.playwright-mcp/audit/`.*
