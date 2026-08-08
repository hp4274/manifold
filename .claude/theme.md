# Theme — Manifold Clean Energy

Design tokens and visual rules for this site. Source of truth is the `:root` block
at the top of `assets/css/style.css`; this file mirrors it for quick reference.
When a token changes in the CSS, update it here too.

## Colours

| Token | Value | Used for |
|---|---|---|
| `--navy-900` | `#03121f` | Deepest dark surfaces |
| `--navy-800` | `#061a28` | Header / dark sections |
| `--navy-700` | `#052640` | Dark gradients |
| `--navy-600` | `#062f4d` | Dark gradients |
| `--navy-500` | `#0a3252` | Dark gradients |
| `--ink` | `#0f2c4d` | Headings |
| `--ink-soft` | `#294864` | Secondary headings |
| `--body` | `#5b7186` | Body copy |
| `--muted` | `#8499ac` | Captions, meta text |
| `--accent` | `#4bb453` | Primary mint green — buttons, links, highlights |
| `--accent-2` | `#17b0a6` | Button gradient end (teal) |
| `--accent-deep` | `#0e8f96` | Deep teal accents |
| `--nav-ink` | `#04223a` | Navbar link colour, top bar background |
| `--nav-active` | `#4bb453` | Active / hover navbar link |
| `--tint` | `#f6f9fc` | Light section background |
| `--line` | `#e3ebf2` | Borders, dividers |
| `--white` | `#fff` | Page background, cards |

Primary gradient: `--accent` → `--accent-2` (green to teal), left to right.

## Typography

- Family: `--ff` = `"Figtree", "Poppins", "Segoe UI", system-ui, -apple-system, sans-serif`.
  One family for the whole page — no secondary display face.
- Self-hosted at `assets/vendor/figtree/`, weights 300–800.
- Base size: `--t-sub` (18px), line-height `--t-sub-lh` (1.7).

### Two content tiers (everything below the hero)

Every section uses at most two content type styles. Hierarchy inside a section
comes from weight, colour and spacing — never from a third size.

| Token | Value | Used for |
|---|---|---|
| `--t-title` | `24px` | Section headings, and card headings in sections that have no section heading (feature bar) |
| `--t-sub` | `18px` | Subtitles, leads, body copy, card headings inside a titled section, list items, footer links |
| `--t-title-lh` | `1.35` | Title line-height |
| `--t-sub-lh` | `1.7` | Subtitle / body line-height |

Helper classes `.u-title` and `.u-sub` apply the two tiers directly.

Weight rules: section title 700, card heading 700 at 18px, body 400. Section
titles carry `letter-spacing:-.02em`; body copy none.

### Support styles (chrome, not content tiers)

| Token | Value | Used for |
|---|---|---|
| `--t-ui` | `15px` | Buttons, inputs, footer legal/policy meta |
| `--t-micro` | `11px` | Eyebrows, tags, badges — uppercase, `.16em` tracking |

- `--fs` (currently `1.1`) still scales the header, top bar and hero only.
  Section type is absolute so the 24/18 contract holds at every width.
- Headings `h1–h4`: colour `--ink`, weight 700, line-height `--t-title-lh`;
  `h2–h4` default to `--t-title`.
- Every section opens with `.eyebrow.eyebrow--rule` (label + 58px rule) so the
  entry point is identical section to section. Card-level eyebrows use plain
  `.eyebrow` (muted, no rule).

## Shape and depth

- `--radius-sm` `10px`, `--radius` `14px`, `--radius-lg` `18px`
- `--shadow-card` `0 10px 34px rgba(10,45,80,.07)`
- Buttons use fully rounded pills (`.btn-pill`), variants `--accent` and `--ghost`.

## Layout

- `--sec-pad` `110px` — vertical rhythm for every `.section` below the hero
  (`76px` ≤991px, `60px` ≤575px). `.section--flush-top` zeroes the top padding
  when two sections share one background band.
- `--container` `1463px` — max content width (`.container-x`)
- `--topbar-h` `45px`, `--header-h` `74px`
- `--header-offset` = topbar + header; used for `scroll-padding-top` so anchor
  links do not land under the fixed header.

## Breakpoints

| Width | Layout |
|---|---|
| ≥ 1200px | Full desktop, four-column grids |
| 992–1199px | Tighter navigation and spacing |
| ≤ 991px | Hamburger menu, stacked product cards, two-column flow |
| ≤ 767px | Hero panels stack vertically, single-column cards |
| ≤ 575px | Compact padding and type |

## Section inventory (style.css)

Sections 1–14 are listed in the file header. Later additions: `9b` Proof
(home page "Trusted & proven" band), `13` Product page, `13b` Contact page,
`13c` Application form, `13d` Coming soon, `13e` Legal pages.

## Conventions

- Vanilla HTML/CSS/JS plus Bootstrap 5.3.3 — no build step, no framework.
- All third-party libraries are self-hosted under `assets/vendor/` so the site
  works offline. Do not swap in CDN links without being asked.
- All custom styling lives in `assets/css/style.css`, organised into the 13
  numbered sections listed in its header comment. Add new rules to the matching
  section rather than appending to the end of the file.
- Behaviour lives in `assets/js/main.js`: mobile menu, sticky header
  (`.is-stuck` after 40px scroll), `IntersectionObserver` scroll reveal on
  `.reveal`, and active-nav-link highlighting.
- Accessibility is expected, not optional: visible focus rings, ARIA labels on
  icon-only controls and the menu toggle, alt text on every image, and
  `prefers-reduced-motion` respected for animation.
- Use the CSS custom properties above instead of hard-coded hex values.
