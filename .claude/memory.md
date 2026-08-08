# Project Memory — Manifold Clean Energy

Rolling log of decisions, context and outcomes from Claude Code sessions on this
project. Newest entries go at the top of the Log section.

## How this file is maintained

At the end of each successful chat, append a new dated entry to the Log below
covering: what was asked, what changed (with file paths), any decision worth
remembering, and anything left open. Keep entries short. Do not record things
already obvious from the code or the README.

## Standing context

- Static one-page marketing site for Manifold Clean Energy Pvt. Ltd. (hydrogen
  cooking stove and hydrogen conversion kits for auto rickshaws, Ahmedabad).
- Stack: HTML5, CSS3, vanilla JavaScript, Bootstrap 5.3.3. No build step.
- Served from XAMPP at `c:\xampp\htdocs\manifold`. Not a git repository.
- Entry point `index.html`; styling `assets/css/style.css`; behaviour
  `assets/js/main.js`. Design tokens documented in `.claude/theme.md`.
- Libraries self-hosted under `assets/vendor/` so the site works offline.

## Log

### 2026-08-08 — Details tabs consolidated to five

- Thirteen tabs was still too many, so `field_groups()` now returns
  **tab => section => fields** and related things share a tab, each keeping its
  own subheading inside the panel.
- Applications: **Payment · Applicant · Site & address · Requirement · Admin**.
  Address, property, water supply and the technical assessment all describe
  where the unit goes, so they live under Site & address; consumption joined
  Requirement; payment preference, referral, consent and tracking became Admin.
- Contact and newsletter now have one tab with two subsections, so the tab bar
  is skipped entirely for them.
- `partials/drawer-source.php` renders the extra nesting level; nothing else
  consumed `field_groups()`.


### 2026-08-08 — Details drawer: wider, with section tabs

- A full application showed thirteen field groups stacked, so the drawer was an
  endless scroll.
- `partials/drawer-source.php` now renders a tab bar (`.detail-tabs`) and one
  `.detail-panel` per group, with only the first visible. The payment panel is
  the first tab on an application; contact and newsletter have a single group so
  the bar is skipped entirely.
- Switching is handled in `assets/admin.js`, delegated on `#drawerBody` because
  the markup is cloned into the drawer at open time. The tab bar is sticky and
  scrolls sideways; the body scrolls back to the top on each switch.
- Drawer widened 1040px → **1280px** (96vw cap). Fields inside a panel now flow
  into as many `minmax(340px,1fr)` columns as fit, each row separated by a
  hairline, one column on a phone.
- Renamed the form's `Payment` field group to **Payment preference** — it was
  colliding with the real Payment tab in the tab bar.


### 2026-08-08 — Receipts are generated PDFs, kept apart from proofs

- The "Receipt" link in the payment panel opened the applicant's uploaded
  screenshot, which is a *proof*, not a receipt — and broke when a proof had
  been deleted. Receipts are now PDFs we issue.
- `admin/receipt-pdf.php` is a hand-written PDF 1.4 writer (SimplePdf: text,
  rules, filled rects, Helvetica base-14) plus `build_receipt_pdf()`. No
  Composer, no vendor directory, in keeping with the rest of the project.
  Rupee signs and dashes are folded to ASCII because base-14 fonts are
  single byte.
- Served by `admin/receipt.php?payment=N` (admin session) and
  `portal/receipt.php?payment=N` (applicant session, scoped to their own email).
  Nothing is stored on disk — each request regenerates from the payment row, so
  deleting a proof can never destroy a receipt.
- The receipt email now **attaches the PDF** and BCCs `ADMIN_NOTIFY_EMAIL`.
  `mailer.php` grew attachment support: multipart/mixed wrapping the
  related/alternative body.
- Vocabulary is now explicit everywhere: *identity proof* and *address proof*
  (apply form, `uploads/`), *proof of payment* (per transfer,
  `uploads/payments/`), *receipt* (ours, generated). The drawer shows
  "Receipt PDF" and "Proof of payment" as separate links, and says
  "proof removed" where a rejected transfer's file is gone.
- Verified: PDF opens as `application/pdf` (4.5 KB, valid header/EOF, all text
  reads back correctly), endpoint 302s to login without a session, and a live
  receipt email with the attachment was delivered.


### 2026-08-08 — Instalments: pay the fee in parts, a receipt for each

- Scenario raised: an applicant whose bank caps transfers at ₹1,000 pays four
  times and should get four receipts.
- Payments moved out of the `applications` row into a new `payments` table
  (`admin/upgrade-instalments.sql`, already run locally; also in `schema.sql`).
  One row per transfer: amount, reference, proof, status
  (pending/verified/rejected), `receipt_no`, who decided it and when. Existing
  single receipts were migrated in as `-R1`.
- The application's status is now **derived, never set by hand**:
  `status_from_payments()` returns complete when verified payments cover the
  fee, payment_review while any transfer is waiting, otherwise payment_pending.
  `sync_application_status()` writes it back and stamps `completed_at`. Called
  after every upload and every admin decision.
- `payment_totals()` gives due / paid / waiting / balance / percent, used by the
  admin panel, the portal ledger and all three emails.
- Receipts are numbered `MF-2026-00042-R1`, `-R2`… by counting verified
  payments. Each receipt email shows that transfer's amount, the running total
  and the balance; the final one says "paid in full".
- Portal: the upload form asks how much this transfer was (capped at the
  balance), and the card shows a progress bar plus a ledger of every transfer
  with its state and receipt number.
- Admin drawer: one row per transfer with its own Accept / Reject, a progress
  bar, and a reminder button that quotes the outstanding balance.
- Tested live end to end with 1000+1000+1000+500 → four receipt emails
  (R1–R4, balances 2500/1500/500/0) and the application auto-completed. Test
  rows, orphaned receipt files and OTPs were removed afterwards.


### 2026-08-08 — Payment-first application flow

- Flow rewritten on request: the applicant pays as soon as the form is
  submitted. There is no admin approval before payment, so the old
  new/pending/confirmed steps are gone.
- Application statuses are now `payment_pending → payment_review → complete`
  (plus `rejected`). `admin/upgrade-payment-flow.sql` migrates an existing
  database and has been run locally; `schema.sql` matches for fresh installs.
- `submit.php` sends the payment email itself (reference, QR, fee) the moment an
  application is stored. The fee is `PAYMENT_AMOUNT` (₹3,500) in `config.php`,
  copied into each row's `payment_amount` so changing it later cannot rewrite
  history. `money()` formats it.
- New `admin/payment.php` handles the three payment decisions; new
  `partials/payment-panel.php` renders them at the top of an application's
  Details drawer. Accept → `complete` + receipt email. Reject → back to
  `payment_pending`, reason emailed, and the receipt file is deleted from disk.
  Remind → re-sends the QR, bumps `reminder_count`.
- **Application rows no longer carry status buttons** — only delete. Payments
  are decided in the drawer, where the receipt can actually be seen.
  `status.php` now refuses any application and answers 409; it serves contact
  and newsletter only.
- Four new email templates in `emails.php`: application received / pay,
  reminder, receipt (itemised table), payment rejected. All five paths were
  delivered live to the client's inbox during testing.
- Portal: the upload appears while `payment_pending`, moves the record to
  `payment_review`, and a rejected receipt shows the admin's reason above the
  upload form.
- Test row #7 was created and deleted afterwards; the three real applications
  (#3, #4, #6) and their receipts were left alone.


### 2026-08-06 — Mobile pass over the public site, portal and admin

- Asked to fix sections that did not fit a phone screen.
- New block **14b. Mobile refinements** at the end of `assets/css/style.css`
  (plus a `@media (max-width:991px)` block for the coming-soon page, which had
  no mobile rules at all). Highlights: the technology flow drops to one step per
  row with a smaller orb; the About visual loses its floating arc, leaf and
  sprig and centres the badge in the flow; `.why-panel`, `.proof-card`,
  `.icon-card`, `.contact-card`, `.cta-inner`, `.side-panel`, `.legal-nav` and
  the portal cards get tighter padding; the form toast switches from a
  transform-centred pill to a full-width bar.
- `body{overflow-x:hidden}` added as a guard so decorative absolutes can never
  produce a sideways scroll, and `.spec-table-wrap` now scrolls internally.
- `admin/assets/admin.css` gained a `@media (max-width:640px)` block: the detail
  drawer goes full-width, its field lists become one column, and the tiles,
  filters and login card tighten up.
- Type tiers were left alone — nothing shrinks below the 24/18 contract.
- Not verified in a real browser or device; checked by reading the CSS and by
  confirming both stylesheets still serve and parse.


### 2026-08-06 — About and Why hydrogen redesigned from a reference

- Asked to rebuild both blocks to match a supplied screenshot, in the site theme.
- About is now two columns instead of three: copy on the left (eyebrow, title,
  new `.about-rule` under it, two shorter paragraphs, the two stats), and the
  photo on the right with a thin ring (`.about-trace__arc` reworked from a
  dashed offset outline), the circular "Clean Energy Brighter Tomorrow" badge
  overlapping its left edge, a small leaf medallion (`.about-leaf`), a dot grid
  (`.about-dots--tl`) and an inline SVG leaf sprig (`.about-sprig`).
- Why hydrogen moved out of the third column into a full-width `.why-panel`
  below the intro: centred eyebrow (`.eyebrow--center`) over four cards in a
  Bootstrap row. Each card is icon chip + a filled number badge notched into its
  corner, then the title above a hairline rule, then the description.
- Retired selectors `.about-trace`, `.about-node`, `.why-body`, `.why-head` —
  removed from the CSS along with their markup.

### 2026-08-06 — Payment flow, applicant portal and SMTP email

- Asked for: accepted applications trigger an email with a payment QR and an
  upload link; the applicant uploads proof; the admin verifies and completes;
  the applicant tracks all of it by logging in with email + OTP from the navbar
  Login button.
- **Status flow replaced for applications** (contact and newsletter keep the old
  four): `new → pending → confirmed → payment_pending → complete`, plus
  `rejected`. `payment_pending` means "receipt uploaded, awaiting verification"
  and is set by the applicant, not the admin. `admin/lib.php` now has
  `statuses_for()`, `status_label()`, `status_short()` and `stage_copy()` — do
  not use the `STATUSES` constant for applications.
- Schema: `applications` gained `reference_code` (unique, `MF-YYYY-00042`,
  written straight after insert), `payment_reference`, `payment_proof_path`,
  `payment_uploaded_at`, `payment_verified_at`, `confirmed_at`, `completed_at`.
  New tables `applicant_otps` and `email_log`. `admin/upgrade-portal.sql`
  migrates an already-imported database and maps accepted→confirmed,
  contacted→pending; it has been run against the local database.
- `admin/mailer.php` is a dependency-free SMTP client (AUTH LOGIN, STARTTLS or
  SSL, one inline image for the QR). `admin/emails.php` holds the three
  templates. **SMTP credentials in `config.php` are deliberately blank — the
  user fills them in.** Nothing breaks without them: sends fail, the reason is
  written to `email_log`, and the admin shows a warning banner.
- QR code is read from `assets/images/qr.jpeg` (not committed — the user
  supplies it). Missing file degrades to a "sent separately" line.
- New `portal/`: OTP sign-in (10 min, 5 guesses, 6 per hour, same answer whether
  or not the address exists), an application card per record with a five-stage
  timeline, and a receipt upload that only appears while `confirmed`. Receipts
  live in `admin/uploads/payments/` behind an `.htaccess` deny and are served
  only through `admin/file.php?dir=payments`.
- Navbar Login on all eight pages now points at `portal/index.php`.
- Portal styling is section 13f of the public `style.css`, so it inherits the
  24/18 tiers rather than duplicating admin CSS.
- Verified end to end on localhost: application submitted → reference generated
  → OTP issued (send failed as expected with blank SMTP, logged) → signed in →
  receipt uploaded → status became `payment_pending` → direct URL to the receipt
  returned 403. Test rows were deleted afterwards.
- SMTP was then configured with the client's Gmail account
  (`harshlpatel.4274@gmail.com`, app password, smtp.gmail.com:587 TLS). Gmail
  refuses a `From` that is not the authenticated mailbox, so `MAIL_FROM` is the
  Gmail address and `MAIL_REPLY_TO` stays `info@manifoldcleanenergy.com`. Two
  live test emails (OTP and payment) were delivered successfully.
- The app password sits in plain text in `admin/config.php`. This is not a git
  repository, but the file must never be served or copied to a public host as-is
  — flagged to the user.
- The QR image turned out to live at the project **root** (`qr.jpeg`), not in
  `assets/images/`. `config.php` now resolves it through `qr_path()` /
  `qr_file()`, which try `qr.jpeg`, `qr.jpg`, `qr.png`,
  `assets/images/qr.jpeg`, `assets/images/qr.png` in that order — so moving the
  file later does not break anything. The inline attachment also detects its own
  MIME type instead of assuming JPEG. Re-tested live: payment email delivered
  with the QR embedded.
- Open: no email to the admin when a receipt arrives; the portal shows
  applications only, not contact enquiries.

### 2026-08-06 — "Trusted & proven" band on the home page

- Asked for a new home-page section under Our journey, matching a supplied
  reference screenshot but rendered in this site's theme.
- Added `#proof` to `index.html` between the milestones section and the CTA:
  eyebrow, 24px title with an accent-teal span, 18px lead, three cards
  (TRL 9 / Academic review / 13 of 17) and a row of five pill badges.
- New CSS block 9b in `assets/css/style.css` (`.proof`, `.proof-card`,
  `.proof-card__label`, `.proof-card__rule`, `.proof-tags`, `.proof-tag`), plus
  a dot-grid decoration hidden below 992px. Reference used a different type
  scale; kept the site's 24/18 tiers instead of copying it.
- Layout went through two passes: a dark navy version copied from a second
  reference, then back to the site palette on request. Final state keeps the
  reference *layout* (round icon chip, label, title, rule under the title, body,
  "Start the conversation" link to contact.html) but renders it light —
  white cards, `--line` borders, `--shadow-card`, `--ink` headings,
  `--accent-deep` accents, white-to-`--tint` band. Lesson: match the site
  palette by default and treat supplied screenshots as layout references.
- Claims in the copy (TRL 9, seven years of trials, review by global
  universities, 13 of 17 SDGs) came from the reference image and are unverified.
- `.claude/theme.md` now lists the lettered CSS sections, since numbering has
  grown past the original 13.

### 2026-08-06 — Admin area: login, dashboard, PHP backend, MySQL schema

- Asked for an `admin/` folder with a login and dashboard covering the four
  website forms (two applications, contact, newsletter), each with the statuses
  new / accepted / contacted / rejected.
- Stack decision: plain PHP 7.4+ with PDO MySQL, no framework and no build step,
  to match the rest of the project. Admin styling is a separate
  `admin/assets/admin.css` that redeclares the same tokens — the admin does not
  load the public `style.css`.
- `admin/schema.sql` creates the `manifold` database: `admin_users`,
  `applications` (both apply forms in one table, split by a `product` enum, with
  column names matching the form field names), `contact_messages`,
  `newsletter_subscribers`, `status_log` (audit trail) and `login_attempts`
  (throttling).
- Pages: `login.php`, `logout.php`, `index.php` (tiles + latest ten),
  `list.php?type=…&status=…`, `view.php?type=…&id=…` (full record, status
  select, internal note, history), `file.php` (auth-gated upload serving),
  `create-admin.php` (one-time, refuses once an account exists — tell the user
  to delete it), `partials/layout-*.php`.
- `admin/submit.php` is the public endpoint for all four forms, switched on a
  hidden `form` field. It answers JSON to fetch callers and redirects with
  `?sent=1` / `?error=1` otherwise. Includes a honeypot field named `website`.
- Wiring: `assets/js/apply.js` now POSTs to it instead of logging to the console;
  the contact and footer newsletter forms post normally, and a new toast in
  `assets/js/main.js` (styled in section 12 of `style.css`) reports the result.
- Uploads go to `admin/uploads/` with random names, MIME-checked against a
  four-type allowlist, 10 MB cap, and an `.htaccess` that denies direct access
  and script execution. Needs `AllowOverride All` on Apache; nginx needs an
  equivalent rule.
- The row control went through two shapes: first a self-submitting status
  select, then (on request) a dedicated **Actions** column of icon buttons —
  accept ✓, contact ☎, reject ✕, delete 🗑 — beside a read-only status pill.
  The first three post to `status.php`; delete posts to the new `delete.php`,
  which also unlinks the record's uploaded files and its `status_log` rows.
  Delete is confirmed in `admin.js` via `data-confirm`. Nothing sets a record
  back to `new` by design.
- Earlier the same day, on request: **`view.php` was deleted**. "Details" expands
  the row in place to show every field plus the internal note box. Field groups
  and `render_value()` moved from `view.php` into `lib.php`; the switcher markup
  lives in `partials/row-actions.php`; behaviour in `assets/admin.js`. The
  dashboard's latest-ten table has the same inline switcher.
- Open: no email notification on new submissions; no pagination beyond the 300
  row cap in `list.php`; admin is HTTP-only until the site gets TLS.

### 2026-08-06 — Legal page with a document switcher

- Asked for one page holding all seven legal documents, with the list on the
  left and only the selected document shown on the right, no imagery.
- New `privacy-policy.html`: sticky left tablist (Privacy Policy, Terms of
  Service, Website Terms, Refund Policy, Data Deletion, Disclosure Policy,
  Cookies) and a single panel on the right showing one `.legal-doc` at a time.
  Only the shared header and footer around it.
- New `assets/js/legal.js` (loaded only by that page): toggles the `hidden`
  attribute and `aria-selected`, syncs the URL hash with `replaceState`, and
  responds to `hashchange` — so `privacy-policy.html#cookies` opens straight at
  the cookie policy. With JS off, the first document is the one marked visible.
- The seven footer legal links on all eight pages now point at
  `privacy-policy.html#<slug>` with slugs matching the article ids.
- New CSS block 13e (`.legal-head`, `.legal-layout`, `.legal-nav`,
  `.legal-panel`, `.legal-doc`). The layout collapses to one column ≤991px and
  the sidebar stops being sticky there.
- All policy text is drafted placeholder content dated 6 August 2026 and has not
  been reviewed by a lawyer. It states an application processing fee, a 14-day
  cancellation window, 30-day deletion turnaround and 2-year enquiry retention —
  confirm each of those before publishing.

### 2026-08-06 — Coming soon page, plus button and icon-chip fixes

- New `coming-soon.html`: dark gradient panel with the shared header and footer,
  a badge, headline, and three shortcut cards to the stove page, the TukTuk page
  and contact. Carries `<meta name="robots" content="noindex">`. CSS lives in a
  new block 13d (`.soon`, `.soon-glow`, `.soon-link`).
- Every unbuilt footer link on all six pages now points at it: Our Team,
  Milestones, Partners, Blog, Newsroom, Case Studies, Downloads, FAQs, Service
  Centres, Warranty, Report an Issue, Sitemap. Careers was removed from the
  Company column everywhere.
- Buttons: new `.btn-pill--white` (white background, `--ink` text, hairline
  border inside the header) is now used by the navbar Get In touch button and
  every hero primary action. `.btn-pill--accent` keeps the gradient with white
  text and is left only on the three form submit buttons.
- Icon chips: every round chip was centring with `display:grid;place-items:center`,
  which left the glyph high and left. All chips now use flex centring with
  `line-height:1`, the `<i>` set to `display:block`. The first attempt at this
  failed because the shared rule sat above the component rules and lost on
  source order — fix the component rule itself, not a blanket rule earlier in
  the file.
- Form numbering circles enlarged to 46px with white 18px numerals; submit
  button text white.
- Bulk link edits were done with `perl -0pi` in Git Bash, which round-trips the
  BOM-less UTF-8 correctly (unlike PowerShell — see the Contact Us entry).

### 2026-08-06 — Added both application pages (TukTuk and Stove)

- Asked for an apply page for the TukTuk kit, then one for the stove, taking the
  form fields from a supplied Tailwind-based reference file.
- New `apply-tuktuk.html` and `apply-stove.html`. The reference markup was not
  reused — it is Tailwind and a different design system. The **field names were
  copied verbatim** so any backend written against the reference file works
  unchanged: all 46 names from sections 1–12 are present on both pages
  (`full_name` … `terms_accepted`), including the shared radio names
  `dedicated_kitchen`, `countertop_space`, `existing_gas`, `existing_electric`
  even though the stove page labels them for a kitchen and the TukTuk page for a
  workshop. Do not rename these without changing both pages.
- Stove page wording differs where the reference was auto-adapted: cooking fuel
  options, "number of stoves required", daily cooking hours, kitchen/counter-top
  questions.
- New CSS block 13c: `.apply-form`, `.form-step`, `.field-grid`, `.upload`,
  `.choice` yes/no pills (`:has(input:checked)` for the selected state),
  `.terms-box`, `.form-actions`, `.form-done`. Same 24px/18px tiers.
- New `assets/js/apply.js`, loaded only by the two apply pages: file-name labels
  in the upload boxes, a required-field guard, and an in-place success panel.
  Submission is `preventDefault` + `console.log` — the fetch call to wire up is
  commented in the file.
- The Apply Now dropdown on every page now points at the two apply pages rather
  than the product pages, and the product-page CTAs read "Apply for a stove" /
  "Apply for a kit" and link to the forms.
- Open: no backend; the reference file also implied a `portal.html`
  ("My applications") that does not exist here.

### 2026-08-06 — Added the Contact Us page and rewired every contact link

- Asked for a contact page with a form and the real office details, plus the
  supplied Google Maps embed, linked from the footer Contact Us quick link and
  every Get In touch button.
- New `contact.html`: page hero (hero-city.jpg background via
  `.page-hero--contact`), four detail cards (phone +91 97251 54186, email,
  the SAFAL Prelude address, office hours), enquiry form beside the map embed,
  a visiting-the-office panel and a call-us CTA. Office hours and the visiting
  notes are placeholder copy; phone, address and the map embed are the real
  values supplied by the user.
- The form is markup only — no backend. It carries a visible note pointing to
  the phone number and inbox until it is wired up.
- New CSS block 13b in `assets/css/style.css`: `.contact-card`, `.form-panel`,
  `.form-x` fields, `.field-row`, `.field-consent`, `.map-frame`, plus the hero
  modifier. Form labels and inputs sit on the 18px subtitle tier so the page
  keeps the two-tier system.
- Link rewiring across all four pages: nav Contact, both nav Get In touch
  buttons, the home hero Partner with us button, the home CTA band button and
  every footer Company/Support contact link now point at `contact.html`. The
  product pages keep their own on-page Request a quote / Book a fitment anchors.
- **Encoding note (cost an hour):** editing `index.html` with PowerShell
  `Get-Content -Raw` + `Set-Content -Encoding utf8` double-encoded the file —
  every em dash became mojibake and a BOM was added. Repairing it lost the
  original bytes, so the seven dashes had to be retyped by hand. Use the Edit
  tool for these files, never a PowerShell read-modify-write; the HTML is UTF-8
  without a BOM and PowerShell 5.1 does not round-trip it.
- Follow-up fix: the Reach us cards were laid out icon-beside-text, which left
  only ~200px for the copy — the email address ran into the card edge and the
  five-line address made one card far taller than its neighbours. The icon now
  stacks above a full-width `.contact-card__body`, the text gets
  `overflow-wrap:anywhere`, the address is three lines and each card carries a
  second line so the four heights match.
- Open: form still needs a backend or a mailto fallback; office hours need
  confirming.

### 2026-08-06 — Added the TukTuk conversion kit product page

- Asked for a second product page matching the stove page, linked from the
  Learn more button on the TukTuk card.
- New `tuktuk.html` mirrors `stove.html` section for section (page hero,
  highlight bar, overview, six feature cards, four steps, gallery, spec table
  with In-the-kit and homologation panels, six-item FAQ, CTA). Copy is invented
  placeholder data (model MH-3W, 145 km range, ₹1.10/km, 9 L tank) — replace
  before launch.
- Only new CSS is `.page-hero--tuktuk`, which swaps the hero background to
  `app-2.jpg`; everything else reuses the section 13 product-page components.
- Four more placeholder SVGs: `assets/images/tuktuk-detail-{unit,engine,dash,road}.svg`.
- Cross-links updated everywhere: the index TukTuk Learn more button, the
  Apply Now dropdown and the footer Products column on all three pages now
  point at `tuktuk.html` / `stove.html` rather than home-page anchors.
- Open: neither product page has been opened in a browser; the pilot/RTO trial
  claims on this page are placeholder text and need legal sign-off.

### 2026-08-06 — Added the Kinetic Hydrogen Cooking Stove product page

- Asked for a second page for the stove, reusing the index navbar, footer and
  theme, with placeholder copy and images, linked from the Learn more button in
  the Our products section.
- New `stove.html`: page hero with breadcrumb, highlight bar, overview split,
  six feature cards, four numbered steps, placeholder gallery, specification
  table with two side panels, six-item FAQ and the shared CTA band. All content
  copy is invented placeholder data (model KH-100, 2.4 kW, ₹18/day and so on) —
  replace before launch.
- New CSS section 13 "Product page" in `assets/css/style.css` (Responsive is now
  section 14): `.page-hero`, `.breadcrumb-x`, `.media-frame`, `.icon-card`,
  `.step-list`/`.step-card`, `.shot`, `.spec-table`, `.side-panel`, `.faq-item`.
  Everything uses the 24px/18px tiers, so the page reads as one system with the
  home page.
- Four placeholder SVG renders added at `assets/images/stove-detail-*.svg`
  (burner, cell, panel, kitchen) — drawn in the theme colours, no binary assets.
- Cross-links: index Learn more button, the Apply Now → Stove dropdown item and
  the footer Products column now point at `stove.html`. The stove page's own nav
  and footer links point back with `index.html#…` since they are cross-page.
- Decision: the FAQ toggle uses a plain "+" glyph rotated to "×" rather than a
  Bootstrap Icons codepoint, so it cannot break if the icon font version changes.
- Open: page not opened in a browser yet; placeholder copy and the pilot-only
  claims need sign-off before this goes public.

### 2026-08-06 — Typographic hierarchy pass on all sections below the hero

- Asked, as a UI/UX redesign, to give every section after the hero a consistent
  visual hierarchy: one font family, 24px titles, 18px subtitles, and no more
  than two type styles per section.
- Added type tokens to `:root` in `assets/css/style.css`: `--t-title` (24px),
  `--t-sub` (18px), `--t-title-lh`, `--t-sub-lh`, plus `--t-ui` (15px controls),
  `--t-micro` (11px labels) and `--sec-pad` (110px section rhythm). Retyped the
  feature bar, products, applications, technology, about, milestones, CTA and
  footer against those tokens and removed the old per-component `calc(px * --fs)`
  sizes and `clamp()` heading scales from those sections.
- Decision: the two content tiers are 24px title and 18px subtitle/body only.
  Card headings inside a section that already has a 24px section title drop to
  18px/700, so hierarchy comes from weight, colour and spacing rather than a
  third size. Buttons/inputs (`--t-ui`) and eyebrows/tags/badges (`--t-micro`)
  are treated as chrome, not as content tiers.
- `--fs` (1.1) now scales only the top bar, header and hero; section type is
  absolute so 24/18 holds at every breakpoint.
- Removed the ad-hoc inline `style="padding-top/bottom"` values from every
  section in `index.html`; spacing now comes from `.section` and the new
  `.section--flush-top` (applications sits flush under products in the same
  tinted band). Section eyebrows unified to `.eyebrow--rule`, which moved from
  the About block to the shared block in section 2 of the CSS.
- Sizing knock-ons: app cards taller (`1/1.28`), timeline min-width 1480px,
  about badge 200px — all to fit 18px copy.
- Open: not visually verified in a browser; check the applications cards and the
  6-column footer at 1200–1400px, where 18px copy is tightest.

### 2026-08-06 — Set up project memory and theme files

- Asked for `.claude/memory.md` and `.claude/theme.md`, with `memory.md` updated
  at the end of each successful chat.
- Created `.claude/theme.md` documenting the colour, type, shape, layout and
  breakpoint tokens mirrored from the `:root` block in `assets/css/style.css`,
  plus project conventions.
- Created this file with the maintenance rule and standing project context.
- Open: nothing.
