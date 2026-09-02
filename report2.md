# UI and UX review — the public website

**Date:** 2 September 2026
**Scope:** the ten public pages — `index`, `stove`, `tuktuk`, `technology`, `blog`,
`contact`, `apply-stove`, `apply-tuktuk`, `privacy-policy`, `coming-soon`
**Environment:** XAMPP / Apache on `http://localhost/manifold`, Chromium 151 headless
**Method:** every page loaded at 390 × 844, 768 × 1024 and 1440 × 900 and measured in the
live DOM — computed colour against composited backgrounds, hit-target geometry, heading
order, label binding, type size and measure, resource weight and timing — plus a
keyboard-only tab walk, the mobile menu, the twelve-step application form and the consent
and promotion layers. Screenshots were taken at each breakpoint and reviewed by eye. This
review looks only at design and experience; correctness and security are covered in
`report.md`.

---

## 1. Verdict

The design system underneath this site is genuinely good, and it is doing most of the work
correctly without being asked twice. One typographic scale, one spacing rhythm, one radius
family, a single accent, real focus rings on every interactive element, forty-six
`prefers-reduced-motion` blocks, semantic landmarks and a skip link on all ten pages, every
form field labelled, every image carrying alt text and intrinsic dimensions. Across thirty
page loads there was **no horizontal overflow at any breakpoint, no console error, no failed
request and no layout shift from unsized media**. That is a higher floor than most sites of
this size reach.

What holds it back is not the visual language. It is three things, in this order:

1. **The site interrupts the visitor three times before they have read anything.** A cookie
   bar, a loan-offer strip and a full-screen welcome modal all fire on arrival. The cookie
   bar is pinned over the page rather than reserving space in it, so at 1440 × 900 it lies
   across both hero buttons, and on the application form it covers whichever fields happen
   to be in the bottom 160 px.
2. **The brand teal fails contrast in the places it is used most** — the pill button, the
   step numbers, the accent phrases and the blog metadata. Eight distinct colour pairs are
   below WCAG AA, several on the primary call to action.
3. **Everything is a few pixels short of a comfortable touch target**, and the shortfall is
   systematic rather than accidental: buttons are 39–41 px tall against a 44 px floor, the
   social icons are 26 px, the menu toggle is 42 × 38.

None of these is hard to fix. The first is a decision about sequencing, the second is four
hex values, the third is one padding rule. Together they are the difference between a site
that looks well designed and one that behaves well designed.

| Area | Result |
|---|---|
| Layout and responsive behaviour (10 pages × 3 breakpoints) | **Pass** — no overflow, no shift, no broken reflow |
| Visual system: type, colour, spacing, radius, elevation | **Pass** — coherent and consistently applied |
| Colour contrast | **Fail** — 8 pairs below AA, including the primary pill button |
| Touch targets | **Fail** — 21 distinct controls under 44 px on mobile |
| Entry experience (consent, promotion, offer bar) | **Fail** — three interruptions, one modal without a focus trap |
| Keyboard operation | **Pass with one exception** — rings everywhere, modal does not trap focus |
| Forms: labels, validation, error recovery | **Pass** — best part of the site |
| Application flow (12 steps) | **Concern** — correct, but far longer than the task needs |
| Content hygiene | **Fail** — QA test posts, including an XSS probe title, are live on the blog |
| Performance and asset discipline | **Fail** — 1.19 MB first load, 390 KB of uncompressed CSS |
| Metadata for search and sharing | **Fail** — no Open Graph tags, no canonical, on any page |

---

## 2. What is working, and should not be touched

Worth stating plainly, because a review that only lists faults invites someone to redesign
things that are already right.

- **The type scale reads as one voice.** Figtree throughout, a clear display-to-body ratio,
  and body copy at 18 px with 1.55 line-height on the pages that carry argument. Nothing is
  set in a second family for decoration.
- **Focus is visible everywhere.** A keyboard walk through the first twenty-eight tab stops
  on the home page found a 2 px `rgb(75,180,83)` ring on every single one. That is rarer
  than it should be and it was clearly deliberate.
- **Motion is answerable.** Forty-six `prefers-reduced-motion` blocks, and the reveal
  animations are entrance-only rather than looping. Nothing moves that a person did not
  cause or scroll to.
- **The forms are the strongest thing here.** Every one of the 56 fields on the application
  is labelled — not placeholder-labelled, actually labelled. Errors appear under the field
  they belong to, in words ("Full name is needed"), and clear the moment the answer becomes
  right. Pressing Next on an empty step marks all six failures at once instead of one at a
  time. This is how it should be done.
- **Images are disciplined.** All eleven on the home page are WebP, ten are lazy, four carry
  `srcset`, and every one has `width`/`height` — which is why nothing shifts as the page
  settles.
- **The stove and TukTuk price sections are the best-designed blocks on the site.** One dark
  band, four figures, each with a one-line explanation of when it is due. A person can read
  the whole commercial proposition in about four seconds.

---

## 3. Findings

### P1 — fix before the site is promoted

#### 3.1 Three interruptions stack on arrival, and two of them cover the content

A first-time visitor to the home page meets, in this order: the loan-offer strip pinned
above the header, the cookie consent bar pinned to the bottom, and — layered over both — a
full-screen welcome modal.

Measured: the consent bar is **160 px tall on a 390 px phone — 19 % of the viewport — and
76 px on desktop**, and it is pinned over the page rather than reserving space in it. At
1440 × 900 it sits across **both** of the home page's hero buttons, "Explore our products"
and "Partner with us". On `apply-stove` at 390 px, scrolled into the form, it covers the
Nationality and Gender selects. It is not large; it is in the way, which is worse.

The welcome modal is worse in a different way: it repeats what the page behind it already
says. Its heading is "Hydrogen on demand, made in India", its body is the same
company sentence as the section underneath, and its button — "See our products" — scrolls
to the section it is covering. It is a door in front of an open door.

It also reappears aggressively. The quiet period after closing it is **five minutes**
(`PROMO_QUIET_MS` in `main.js`), so a visitor who comes back the same afternoon is
introduced to the company again.

> **Do:** show one thing at a time. Consent first, and let it reserve its own height —
> add its measured height as `padding-bottom` on `body` while it is up, so it pushes the
> page rather than covering it, and tighten it to one line and two buttons on mobile
> (roughly 88 px instead of 160). Drop the welcome modal on the home page
> entirely; it duplicates the hero. If it must survive, trigger it on exit intent or on a
> second visit, and raise the quiet period from five minutes to at least thirty days.

#### 3.2 The welcome modal does not trap focus

Tabbing from inside the modal moves to `document.body` and then straight into the page
behind it — the skip link, the offer bar, the top-bar email, the social icons — while the
modal is still on screen and still `aria-modal="true"`. A keyboard or screen-reader user
ends up operating a page they cannot see.

> **Do:** cycle Tab and Shift-Tab within the dialog, send focus to the close button on open,
> return it to the trigger on close, and mark the rest of the document `inert` while it is up.

#### 3.3 Eight colour pairs fail WCAG AA, including the primary button

Measured against composited backgrounds, not guessed:

| Where | Colour on background | Size | Ratio | Needs |
|---|---|---|---|---|
| `.btn-pill--teal` — "Request a quote", "Book a fitment", "Get in touch" | `#fff` on `#17b0a6` | 15 px | **2.69** | 4.5 |
| `.flow-num` — the 01–05 process markers | `#fff` on `#3ba3dc` … `#4cc47f` | 13 px | **2.07–2.82** | 4.5 |
| `.why-num` | `#fff` on `#2f9e94` | 11 px | **3.26** | 4.5 |
| `.tech-title__accent` — "nothing stored, nothing wasted." | `#17b0a6` on `#f6f9fc` | 24 px | **2.55** | 3 |
| Accent phrase — "Zero shortcuts." | `#17b0a6` on `#fff` | 24 px | **2.69** | 3 |
| `.timeline-year`, `.leaf__year` | `#17b0a6` / `#0e8f96` on `#fff` | 11–18 px | **2.69 / 3.90** | 4.5 |
| `.blog-card__meta`, `.blog-card__more` — the date and "Read more" | `#0e8f96` on `#fff` | 11–15 px | **3.90** | 4.5 |
| Email link on the privacy page | `#0e8f96` on `#fff` | 18 px | **3.90** | 4.5 |

Two distinct problems are hiding in that table. `#17b0a6` is simply too light to carry white
text or to sit on white as small text — it is a 2.7 : 1 colour in both directions.
`#0e8f96` is close but not close enough at 3.90 : 1; it needs about a 12 % darkening to
clear 4.5.

> **Do:** keep `#17b0a6` as a decorative and background colour only. Introduce one darker
> token for text and buttons — `#0b7a74` measures **5.18 : 1** both on white and under white
> text, clearing AA in both directions with room to spare — and point `.btn-pill--teal`, `.flow-num`, `.why-num`, the accent spans, the year labels
> and the blog metadata at it. That is a token change, not a redesign, and the palette will
> not look different to anyone who is not measuring it.

#### 3.4 Six links on every page go nowhere

`LinkedIn` and `YouTube` in the top bar, and `LinkedIn`, `YouTube`, `X` and `WhatsApp` in
the footer, are all `href="#"`. They are on all ten pages, so that is sixty dead
destinations. Clicking one jumps the page to the top, which reads as a broken site rather
than an absent account.

> **Do:** point them at the real profiles, or remove the icons until the accounts exist. An
> absent icon costs nothing; a dead one costs trust.

#### 3.5 QA test content is published on the blog

The public blog is currently showing three posts, two of them titled
`QA xss <img src=x onerror=window.__xss=1>` and one `QA test post — hydrogen in Gujarat`.
The markup is being escaped correctly — this is not a security hole — but it is the first
thing a visitor to `/blog` reads, and it is also the source of the only heading-order break
on the site (`h1` → `h3`).

> **Do:** delete the three QA rows, and give the blog an honest empty state until the first
> real article exists — "The first notes are being written" beats three test rows.

#### 3.6 The home page costs 1.19 MB and 390 KB of it is CSS that is not compressed

| Asset | Size | Note |
|---|---|---|
| `bootstrap.min.css` | 233 KB | 21 classes are actually used: `row`, `g-4`, `col-*`, `align-items-center` |
| `style.css` | 158 KB | the site's own, and the one that earns its place |
| `sdfgnfdsesdf.webp` | 164 KB | the hero background — and that is its real filename |
| `favicon.png` | 157 KB | a favicon |
| **First load, home page** | **1.19 MB over 18 requests** | |

No response carries `Content-Encoding`. Gzipped, those two stylesheets are **34 KB and
31 KB** — 66 KB instead of 390 KB, a saving of 325 KB for one line of server configuration.
Caching is right — `public, max-age=31536000, immutable` on assets, `no-cache` on HTML — so
the second visit is cheap, but the first one is not, and the first one is the only one that
counts for a visitor deciding whether to stay.

> **Do:** four things, in descending order of return. Turn on `mod_deflate` or `mod_brotli`
> (390 KB of CSS becomes 66 KB, measured, and it is a config line). Replace Bootstrap with the
> twelve-column grid you actually use — that is about 1 KB of CSS and it deletes 233 KB.
> Rename the hero to something a person can recognise and serve responsive variants of it.
> Regenerate the favicon at 32 px and 180 px, which is a 3 KB file, not a 157 KB one.

---

### P2 — fix in the next design pass

#### 3.7 Twenty-one controls are under the 44 px touch minimum

Measured at 390 px:

| Control | Size | Where |
|---|---|---|
| Consent checkbox | 20 × 20 | apply forms |
| Top-bar social icons | 26 × 26 | all ten pages |
| Footer legal links (Privacy Policy, Terms, Refund…) | height 26 | all pages |
| "Read more" on a blog card | 98 × 26 | index, blog |
| Offer-strip close | 30 × 30 | index |
| Top-bar email link | 32 × 32 | all pages |
| Footer social icons | 38 × 38 | nine pages |
| Every pill button | height **39–41** | every page |
| Menu toggle | 42 × 38 | all pages |
| Skip link | height 31 | all pages |

The pill buttons are the important line in that table. They are the site's primary action in
every context and they are three to five pixels short across the board — which means one
padding value fixes the whole set.

> **Do:** raise `.btn-pill` vertical padding until the box is 46 px, give the icon links a
> 44 px hit area with padding (the icon can stay 26 px — the target grows, the picture does
> not), and space the footer legal links to 44 px rows on mobile. The checkbox should carry
> its label as the target, which it nearly does already.

#### 3.8 The application is twelve steps long

The steps are: Applicant information · Identification details · Residential address ·
Property information · Product requirement · Water supply · Technical assessment · Payment
information · Referral · Environmental impact · Declaration · Terms & conditions.

Four of those are one or two fields. "Referral" is a single code box. "Environmental impact"
and "Declaration" are read-and-tick. A twelve-step counter tells somebody on step two that
they are 17 % done, which is the single most reliable way to lose them — and this form is
asking for money afterwards.

> **Do:** merge to six: Who you are (applicant + ID) · Where it goes (address + property) ·
> What you need (product + water + assessment) · Paying for it (payment + referral) · The
> small print (environmental + declaration + terms). Keep every field; halve the number of
> times somebody has to decide whether to continue. The step labels are already good — they
> name the person's task, not the database table.

#### 3.9 Line length runs to 105 characters

| Page | Median measure | Longest |
|---|---|---|
| `privacy-policy` | **103 ch** | 105 |
| `apply-stove` / `apply-tuktuk` | 85 ch | 105 |
| `stove` / `tuktuk` | 67 ch | 105 |
| `technology` | 42 ch | 105 |

Comfortable reading is 45–75 characters. The legal pages — the ones people read most
carefully and least willingly — are the worst offenders at nearly 105.

> **Do:** cap the reading column at `max-width: 68ch` on `.legal-doc`, the apply form's
> explanatory copy and the long-form sections. The design already does this well on
> `technology`; it just is not applied everywhere.

#### 3.10 Eleven-pixel type is carrying real information

The eyebrow labels, the blog card date and reading time, and the legal document metadata are
all set at 11 px, and `.top-bar__email` at 13.2 px. Below 12 px, and especially below 14 px
for anything a person has to read rather than glance at, the size itself becomes an
accessibility barrier independent of contrast.

> **Do:** floor the eyebrow at 12 px with the existing letter-spacing, and lift the blog and
> legal metadata to 13–14 px. They are already tracked and uppercase, which does most of the
> "this is a label" work — the size does not need to.

#### 3.11 No Open Graph tags and no canonical URL, on any page

Every page has a good `<title>` (28–74 characters) and a real meta description. None has
`og:title`, `og:description`, `og:image` or `link rel=canonical`. A link to this site pasted
into WhatsApp — the channel that matters most for this audience in India — will show a bare
URL with no image and no summary.

> **Do:** add the four Open Graph tags and a Twitter card to each page, with a 1200 × 630
> share image per product, and a canonical URL. This is thirty minutes of work and it is the
> difference between a shared link that sells and one that looks like spam.

#### 3.12 Autocomplete is set on 7 of 56 application fields

Name, email and phone have it. Address, city, state, postcode, date of birth and the ID
fields do not — so a phone that could fill six boxes fills none of them, on the longest form
on the site.

> **Do:** add `autocomplete="street-address"`, `address-level2`, `address-level1`,
> `postal-code`, `bday`, `country-name`. Ten attributes, a measurably shorter form.

#### 3.13 The mobile menu has no visible way out

Opening it sets `aria-expanded="true"` and locks the body scroll, both correct. But there is
no close control inside the panel — the visitor has to find the same hamburger, now behind
the overlay, and two of the eight links are under 44 px tall.

> **Do:** put an explicit × in the panel, and give the links 48 px rows. On a full-screen
> menu the rows are free real estate.

---

### P3 — polish

- **The section head splits at 1440 px.** On the product pages the eyebrow and title sit
  hard left while the supporting sentence sits hard right, leaving 700 px of nothing between
  a heading and the sentence that explains it. They read as unrelated. Cap the pair at
  around 1100 px, or move the sub-copy under the title.
- **"Learn more" twice on the home page.** Both product cards use it. Out of context —
  which is exactly how a screen-reader user meets a link list — neither says what it leads
  to. "Learn more about the stove" costs three words.
- **Hero copy over photography.** The home hero's supporting paragraph sits over the
  brightest part of the image (the road and the sky). The gradient scrim is doing its job on
  the headline but is nearly spent by the time it reaches the paragraph. Extend the scrim
  down or step the paragraph up one weight.
- **Breadcrumbs wrap awkwardly on mobile.** On `apply-stove` at 390 px, "HOME / KINETIC
  HYDROGEN COOKING STOVE / APPLICATION" wraps with "APPLICATION" alone on the second line.
  Truncate the middle crumb with an ellipsis on small screens.
- **The floating "back to top" button shares its corner with the cookie bar** until consent
  is answered — two floating layers competing for the same 60 px of screen.

---

## 4. Page by page

| Page | Verdict | The one thing to fix |
|---|---|---|
| `index` | Strong hero, clear product split, good rhythm | Three interruptions on arrival (§3.1, §3.2) |
| `stove` | Best-structured page on the site; the price band earns its place | Teal button contrast (§3.3) |
| `tuktuk` | Mirrors `stove` correctly — consistency is doing its job | As above |
| `technology` | Best typography and the tightest measure (42 ch median) | Accent-phrase contrast at 2.55 (§3.3) |
| `blog` | Layout is fine; the content is not | QA/XSS test posts are live (§3.5) |
| `contact` | Clean, focused, one obvious action — the least cluttered page here | Autocomplete on the remaining fields |
| `apply-stove` | Excellent validation and error copy | Twelve steps (§3.8); cookie bar covers fields mid-form |
| `apply-tuktuk` | Identical, correctly | As above |
| `privacy-policy` | Complete and well organised | 103-character lines (§3.9) |
| `coming-soon` | Honest, brief, offers a way onward | Nothing beyond the global fixes |

---

## 5. What to do, in order

| # | Fix | Effort | Return |
|---|---|---|---|
| 1 | Turn on gzip/brotli | 1 line of Apache config | −325 KB on first load |
| 2 | Delete the QA blog posts | 3 DB rows | Removes an XSS-looking string from the public site |
| 3 | Point or remove the six dead social links | 15 min | 60 dead links gone |
| 4 | Darken the teal text/button token to `#0b7a74` | 1 token, ~8 selectors | Clears 8 AA failures |
| 5 | Let the cookie bar reserve its height instead of covering controls | 1 CSS rule + body padding | Unblocks both hero CTAs and the apply form |
| 6 | Drop the welcome modal (or trap focus and raise the quiet period) | 30 min | Removes the biggest entry obstacle |
| 7 | Raise `.btn-pill` to 46 px and pad the icon links to 44 px | 2 CSS rules | Clears most of the target failures |
| 8 | Replace Bootstrap with the grid you use | half a day | −233 KB |
| 9 | Rename and re-size the hero image and the favicon | 30 min | −280 KB |
| 10 | Add Open Graph, Twitter card and canonical | 30 min | Shareable links |
| 11 | Cap the reading measure at 68 ch | 1 CSS rule | Legal and apply pages become readable |
| 12 | Merge the application to six steps | 1 day | The one change most likely to raise completions |

Items 1–7 are under a day together and account for most of the difference a visitor would
notice.

---

## Appendix — method

Chromium 151 headless, driven over the DevTools protocol. Each page was loaded three times,
once per breakpoint, with a fresh profile for the entry-experience tests so consent and
promotion state were never carried over.

- **Contrast** was computed from `getComputedStyle` colours composited against the real
  ancestor background stack, with alpha blending resolved. Where an ancestor carried a
  gradient or a photograph the pair was excluded from the numbers and reviewed from a
  screenshot instead — 20 such places on the home page, 20 on each product page, all of them
  white text on dark scrims that read correctly by eye.
- **Touch targets** are `getBoundingClientRect` measurements at 390 px, excluding inline
  links inside running text, which are not controls.
- **Measure** is column width divided by `0.5 × font-size`, the standard approximation for
  average glyph width; it is accurate to about ±3 characters for this typeface.
- **Keyboard** was a real `Tab` key walk over the first 28 stops with `:focus-visible` and
  the computed outline checked at each one.
- **Weight and timing** come from the Navigation and Resource Timing APIs on a cold profile;
  compression and caching were confirmed separately with `curl`.
