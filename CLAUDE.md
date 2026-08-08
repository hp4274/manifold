# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project

Static one-page marketing site for Manifold Clean Energy Pvt. Ltd. — HTML5, CSS3,
vanilla JavaScript and Bootstrap 5.3.3. No build step and no package manager:
open `index.html` in a browser, or serve the folder with `npx serve .`.

- `index.html` — all page markup
- `assets/css/style.css` — all custom styling, organised into 13 numbered sections
- `assets/js/main.js` — mobile menu, sticky header, scroll reveal, active nav link
- `assets/vendor/` — self-hosted Bootstrap, Bootstrap Icons and Figtree
- `README.md` — structure, sections and customisation notes

## Design system

Read `.claude/theme.md` before making any visual change. It documents the colour,
typography, radius, shadow, layout and breakpoint tokens. Use the CSS custom
properties defined in the `:root` block of `style.css` rather than hard-coded
values, and keep `.claude/theme.md` in sync when a token changes.

## Session memory

`.claude/memory.md` is the rolling project memory.

- Read it at the start of a session for prior context and decisions.
- **At the end of every successful chat, append a new dated entry to its Log
  section** (newest first) covering what was asked, what changed and where, any
  decision worth remembering, and anything left open. Keep entries short and skip
  anything already obvious from the code or the README.
