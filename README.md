# Manifold Clean Energy — Website

A static, fully responsive one-page website built from the supplied design mockup
using **HTML5, CSS3, vanilla JavaScript and Bootstrap 5**.

## Folder structure

```
manifold-clean-energy/
├── index.html                      Full page markup (all sections)
├── README.md
└── assets/
    ├── css/
    │   └── style.css               All custom styling
    ├── js/
    │   └── main.js                 Menu, sticky header, scroll reveal, active link
    ├── images/                     Photography, product shots, partner logos
    │   ├── hero-city.jpg           Hero — left panel
    │   ├── hero-kitchen.jpg        Hero — right panel
    │   ├── app-1..4.jpg            Application cards
    │   ├── product-stove.jpg       Kinetic Hydrogen Cooking Stove
    │   ├── product-tuktuk.jpg      Hydrogen Conversion Kit for TukTuk
    │   ├── about-ahmedabad.jpg     About section photo
    │   ├── cta-left.jpg            CTA band glow (left)
    │   ├── cta-right.jpg           CTA band glow (right)
    │   └── *.png                   Partner / supporter logos
    └── vendor/                     Third-party libraries (self-hosted)
        ├── bootstrap/              Bootstrap 5.3.3 — CSS + bundle JS
        ├── bootstrap-icons/        Bootstrap Icons 1.11.3 + webfonts
        └── figtree/                Figtree webfont (Fontsource, OFL 1.1)
```

## Running it

Open `index.html` in any browser — no build step, no server required.
For local development with live reload you can also run:

```bash
npx serve .          # or: python3 -m http.server 8000
```

## Sections

1. Fixed header with hydrogen-molecule logo, seven nav links and a CTA pill
2. Split hero — city / rickshaw on the left, kitchen on the right
3. Dark feature bar — four key benefits
4. Applications — four image cards with category tags and hover arrows
5. Products — two flagship product cards with feature checklists
6. Technology — four-step on-demand hydrogen flow
7. About — company copy, location stats, photo and "Why hydrogen?" list
8. Milestones — nine-year horizontal timeline (2018 → 2026)
9. Partner and supporter logos
10. CTA band with glowing hydrogen artwork
11. Footer — brand, four link columns, legal and social links

## JavaScript behaviour (`assets/js/main.js`)

| Feature | Notes |
|---|---|
| Mobile menu | Hamburger toggle, closes on link click, Escape and resize |
| Sticky header | Adds `.is-stuck` (solid background + blur) after 40px of scroll |
| Scroll reveal | `IntersectionObserver` fades `.reveal` elements in, staggered |
| Active nav link | Highlights the nav item for the section currently in view |

## Customising

**Colours and type** live as CSS custom properties at the top of `style.css`:

```css
:root{
  --navy-800:#061a28;   /* header / dark surfaces */
  --accent:#4bb453;     /* mint green            */
  --accent-2:#17b0a6;   /* button gradient end   */
  --ink:#0f2c4d;        /* headings              */
  --tint:#f6f9fc;       /* light section background */
  --container:1180px;   /* content width         */
}
```

**Images** — replace any file in `assets/images/` keeping the same filename.
The current images were extracted from the design mockup, so they are low
resolution; swap in high-resolution originals for production.

**Libraries** — Bootstrap, Bootstrap Icons and Figtree are self-hosted in
`assets/vendor/` so the site works offline. To use CDNs instead, swap the
`<link>` and `<script>` tags in `index.html` for:

```html
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Figtree:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
```

## Breakpoints

| Width | Layout |
|---|---|
| ≥ 1200px | Full desktop, four-column grids |
| 992–1199px | Tighter navigation and spacing |
| ≤ 991px | Hamburger menu, stacked product cards, two-column flow |
| ≤ 767px | Hero panels stack vertically, single-column cards |
| ≤ 575px | Compact padding and type |

Accessibility: visible keyboard focus rings, ARIA labels on the menu toggle and
icon-only links, alt text on all images, and `prefers-reduced-motion` respected.
