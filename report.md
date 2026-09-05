# Performance report — test.manifoldcleanenergy.co.in

Measured 3–4 September 2026 against the live test deployment on Hostinger.
Chromium 1.62, desktop viewport 1440×900, cold context per sample, three samples
per page with the median reported. Every number below was taken from the running
site, not from the local copy.

Timings come from the browser's own Navigation, Paint and Resource Timing, plus
`PerformanceObserver` for LCP, CLS and long tasks. Transfer sizes are wire bytes
(`transferSize`), not decoded bytes — the difference matters here and is called
out where it does.

---

## 1. The headline

The application itself is fast. Server response is 170–420 ms across every page
measured, both apply forms submit in **under a quarter of a second**, and a
repeat visit costs **10–14 KB** because caching is set up correctly.

The two largest costs on the site are not the application's code:

| Cost | Where | Size of the problem |
|---|---|---|
| Host bot challenge | every first visit, sitewide | **~4.5 s** before the real page starts |
| Google Maps embed | `/contact` only | **460–490 KB** over 19–26 third-party requests |

Everything the application controls is in good shape. Both headline items are
configuration decisions rather than code, and both are reversible.

---

## 2. Public pages

Median of three cold loads, challenge cookie already held so the numbers
describe the page rather than the interstitial.

| Page | TTFB | FCP | LCP | Load | CLS | Req | Wire | DOM |
|---|---|---|---|---|---|---|---|---|
| home | 251 ms | 1168 ms | 1528 ms | 1444 ms | 0.046 | 20 | 328 KB | 770 |
| stove | 275 ms | 1088 ms | 1328 ms | 1268 ms | 0.020 | 17 | 238 KB | 523 |
| tuktuk | 279 ms | 1244 ms | 1472 ms | 1506 ms | **0.110** | 17 | 185 KB | 525 |
| technology | 240 ms | 1104 ms | 1104 ms | 1269 ms | 0.027 | 15 | 150 KB | 433 |
| blog | 282 ms | 1048 ms | 1048 ms | 1292 ms | 0.045 | 16 | 147 KB | 200 |
| contact | 245 ms | 848 ms | 964 ms | 2173 ms | 0.025 | 16 | 182 KB | 315 |
| apply-stove | 244 ms | 1072 ms | 1084 ms | 1298 ms | 0.025 | 19 | 207 KB | 1266 |
| apply-tuktuk | 289 ms | 1072 ms | 1084 ms | 1250 ms | 0.025 | 19 | 183 KB | 1267 |
| privacy-policy | 239 ms | 844 ms | 844 ms | 947 ms | 0 | 15 | 150 KB | 349 |
| coming-soon | 249 ms | 888 ms | 888 ms | 959 ms | 0.007 | 14 | 146 KB | 221 |
| admin login | 270 ms | 724 ms | 724 ms | 727 ms | 0 | 10 | 200 KB | 27 |
| portal login | 245 ms | 1112 ms | 1112 ms | 1208 ms | 0 | 13 | 144 KB | 86 |
| dealer login | **524 ms** | 1388 ms | 1388 ms | 1493 ms | 0 | 13 | 144 KB | 86 |

Against Google's Core Web Vitals thresholds every page passes LCP (< 2.5 s) and
every page but one passes CLS (< 0.1).

**Two things stand out.**

`/tuktuk` has a **CLS of 0.11**, over the 0.1 threshold and five times its
siblings'. Something above the fold is arriving without its dimensions reserved
and pushing the content under it down.

`/dealer/login` has a TTFB of **524 ms** against 240–290 ms everywhere else, and
it is consistent — 555, 524, 498 across three runs, so it is the page and not
the network. It is roughly 250 ms of server work that the other login pages do
not do.

---

## 3. The first visit costs 4.5 seconds, and none of it is the site

This is the largest number in the report.

```
cold visit to /            page's own load          507 ms
                           real page finally shown  4540 ms
```

The host serves a **"Checking your browser before accessing"** interstitial to
every visitor without its `hcdn` cookie. It returns **HTTP 403**, runs a JS
check, then swaps in the real page. The application never sees the request until
that completes.

The application's own work — 202 ms TTFB, 900 ms FCP, 1111 ms load — is a fifth
of what the visitor actually experiences.

This affects every first-time visitor to the site: everyone arriving from a
search result, an ad, a WhatsApp link or a dealer's share link. It is also why an
automated check reports the site as returning 403.

**This is a Hostinger CDN / bot-protection setting, not something in the
codebase.** It is worth deciding whether the protection is wanted on a public
marketing site at all, and if it is, whether it can be limited to POST endpoints
and the admin area rather than every page view.

### Once past it, repeat visits are excellent

| | Cold first visit | Second visit |
|---|---|---|
| `/` | 328 KB, FCP 900 ms | **10 KB**, FCP 416 ms, 16 of 19 files from cache |
| `/apply-stove` | 207 KB, FCP 816 ms | **14 KB**, FCP 412 ms, 15 of 20 from cache |

Caching is doing exactly what it should. Every static asset carries
`public, max-age=31536000, immutable` and the `?v=` stamps mean a deploy
invalidates only what changed. Nothing to fix here.

---

## 4. Compression is working

Brotli is active on the test host for every text asset. No text file over 8 KB
was served uncompressed on any page.

| Asset | Raw | Brotli | Gzip | Saved |
|---|---|---|---|---|
| `style.css` | 166.3 KB | **35.6 KB** | 38.0 KB | 79 % |
| `bootstrap-icons.min.css` | 85.9 KB | **12.4 KB** | 13.3 KB | 86 % |
| `main.js` | 61.3 KB | **12.4 KB** | 17.8 KB | 80 % |
| `admin.css` | 94.5 KB | **16.3 KB** | 24.2 KB | 83 % |
| `admin.js` | 53.9 KB | **8.9 KB** | 16.0 KB | 83 % |
| `apply.js` | 27.6 KB | **8.6 KB** | 9.0 KB | 69 % |

A caution when reading other tools' output: the decoded page weight is 2–3×
the wire weight (home is 609 KB decoded, 328 KB transferred). Anything reporting
the larger figure is describing parse cost, not download cost.

---

## 5. The contact page carries 460 KB of Google Maps

`/contact` is the heaviest page on the site, and none of the weight is ours.

| | Requests | Wire |
|---|---|---|
| Page without third parties | 15 | ~180 KB |
| Google Maps, on load | +19 | **+460 KB** |
| Google Maps, after scrolling | +26 | **+489 KB** |

Broken down by host: `maps.googleapis.com` 384 KB, `maps.gstatic.com` 74 KB,
`www.google.com` 1–26 KB.

The map loads **immediately**, not when scrolled to, so every visitor pays for
it whether or not they ever look at it. It is also what drags the page's `load`
event out to 2173 ms while its FCP is the second-fastest on the site at 848 ms.

The usual fix is to show a static map image and load the interactive embed on
click, which would take the page from ~640 KB to ~180 KB for the majority of
visitors who never touch it.

---

## 6. Fonts are the slowest requests on the site

On `/technology`, the page with the worst FCP (1104 ms), the five slowest
requests are all fonts:

```
figtree-latin-400-normal.woff2          630 ms   11 KB
figtree-latin-700-normal.woff2          624 ms   11 KB
figtree-latin-600-normal.woff2          607 ms   12 KB
figtree-latin-500-normal.woff2          604 ms   11 KB
bootstrap-icons-subset.woff2            597 ms   10 KB
```

They are small — the time is round trips, not bytes. Four Figtree weights are
fetched on every page. Whether all four are actually used above the fold is worth
checking; each one that is not is a 600 ms request competing with the ones that
are.

Render-blocking resources run 3–7 per page, highest on the two apply forms
(7 each).

---

## 7. Forms — all three submit correctly and quickly

Driven end to end against the live site, including real file uploads.

| Form | Server round trip | Result |
|---|---|---|
| apply-stove | **226 ms** | `200` — booking **MF-00000162** |
| apply-tuktuk | **210 ms** | `200` — booking **MF-00000163** |
| contact enquiry | **170 ms** | `200` — enquiry accepted, copy emailed |

The apply forms are 6-step wizards with 1266 DOM nodes, two file uploads and
around 50 fields. Both walked all six steps, uploaded both documents and
submitted successfully. **No console errors and no page errors on either form.**

The submit handler is doing real work in those 226 ms — writing the application,
allocating a booking number and sending mail — which is genuinely quick.

Earlier submissions during setup created **MF-00000160** and **MF-00000161**;
along with 162 and 163 that is four test applications plus one contact enquiry
left in the test database, all under `perf.test.*@yopmail.com` and
`perf.contact.*@yopmail.com`, easy to find and delete.

Email delivery was also verified as a side effect: sign-in codes arrived in the
yopmail inboxes within seconds on every attempt.

### Three interaction defects found while driving the forms

These are not timing problems but they block real clicks, and each one was hit by
an ordinary click on a visible, enabled element.

1. **The cookie consent scrim swallows every click.** On a first visit a
   full-page `.consent-scrim` sits over the page until Accept or Decline is
   pressed. That is deliberate, but the Accept button renders at **835 px** down
   a 900 px viewport — near the bottom edge on a laptop and below the fold on
   anything shorter. A visitor who does not scroll sees a page that has stopped
   responding to clicks with no visible reason.

2. **Error toasts cover the form controls underneath them.** After a failed
   step the `.toast--error` stack intercepts pointer events over the Next
   button — a genuine click is refused with
   `<div class="toast toast--error"> intercepts pointer events`. The user has to
   wait for the toast to expire before they can act on what it told them.

3. **The sticky progress island overlaps the Next button.** Also seen taking the
   click: `<p class="form-progress__label">Step 1 of 6: Who you are</p>
   intercepts pointer events`.

All three are `pointer-events` / stacking issues rather than layout ones, and
each has a one-line fix.

---

## 8. Partner dashboards

Signed in through the real one-time-code flow. Median of three loads each.

### Dealer — `dealer1@yopmail.com`

| Page | TTFB | FCP | LCP | Load | Wire | DOM | Rows |
|---|---|---|---|---|---|---|---|
| dashboard | 209 ms | 876 ms | 876 ms | 1052 ms | 120 KB | 162 | 2 |
| stock | 213 ms | 864 ms | 864 ms | 1029 ms | 121 KB | 177 | 2 |
| clients | 174 ms | 732 ms | 732 ms | 881 ms | 120 KB | 145 | 2 |
| payouts | 188 ms | 692 ms | 692 ms | 822 ms | 121 KB | 161 | 3 |
| profile | 181 ms | 792 ms | 792 ms | 987 ms | 121 KB | 157 | 0 |

### Distributor — `distributor1@yopmail.com`

| Page | TTFB | FCP | LCP | Load | Wire | DOM | Rows |
|---|---|---|---|---|---|---|---|
| dashboard | 206 ms | 824 ms | 824 ms | 999 ms | 121 KB | 219 | 5 |
| dealers | 189 ms | 752 ms | 752 ms | 890 ms | 122 KB | 301 | 10 |
| stock | 182 ms | 952 ms | 952 ms | 1031 ms | 122 KB | 220 | 4 |
| clients | 188 ms | 832 ms | 832 ms | 1036 ms | 122 KB | 347 | 10 |
| payouts | 185 ms | 808 ms | 808 ms | 945 ms | 121 KB | 311 | 19 |
| profile | 172 ms | 744 ms | 744 ms | 926 ms | 121 KB | 159 | 0 |

### Applicant portal — `client1@yopmail.com`

| Page | TTFB | FCP | LCP | Load | Wire | DOM |
|---|---|---|---|---|---|---|
| portal status | 195 ms | 984 ms | 984 ms | 1103 ms | 158 KB | 222 |

Every partner page is under 1.1 s to load, CLS is **zero** on all of them, no
long tasks, no console errors, and each page is a flat 120–122 KB. Server time is
a consistent 170–215 ms whether the page renders 0 rows or 19.

**One caveat on these numbers.** The seeded partners are small — the largest
table measured was 19 rows. These timings say the pages are efficient; they do
not yet say how the queries behave against a distributor with hundreds of dealers
and thousands of clients. The admin lists (164 applications) are where that would
first show, which is the next section.

---

## 9. Admin panel

Not yet measured — pending credentials. To be covered: dashboard, the stove and
tuktuk lists (164 applications, paginated), dealers, distributors, referrals,
stock, commission, raffle, the detail drawer and the MIS export in CSV, PDF and
Excel.

---

## 10. What to do, in order

1. **Turn down or narrow the host's bot challenge.** 4.5 s on every first visit
   dwarfs everything else in this report, and it is a Hostinger panel setting
   rather than a code change. Biggest possible win, smallest possible effort.

2. **Defer the Google Maps embed on `/contact`** behind a static image and a
   click. Removes 460 KB and 19 requests for most visitors, and takes that page's
   `load` from 2173 ms to roughly its 848 ms FCP.

3. **Fix the three click-blocking overlays** in §7. None is a performance number
   but each one stops a real user from pressing a button they can see.

4. **Find the CLS on `/tuktuk`** (0.11, over threshold). Almost certainly an
   image or embed above the fold without reserved dimensions.

5. **Look at `/dealer/login`'s 524 ms TTFB** — ~250 ms more server work than the
   other two login pages, consistently.

6. **Audit the four Figtree weights.** Each unused weight is a 600 ms request
   competing with the ones that are needed.

Nothing in items 3–6 is urgent. Items 1 and 2 are where the visible time is.

---

## Appendix — how this was measured

- `playwright-cli` driving Chromium; scripts kept in `.playwright-cli/`
  (`perf2.js` public pages, `dash.js` dashboards, `forms.js` form submissions,
  `cold.js` cold-vs-warm, `otp.js` reads sign-in codes from yopmail).
- Fresh browser context per sample, so no sample is served from a warm cache.
- Three samples per page, median reported; CLS is the worst of the three.
- The host's challenge cookie is injected for §2 so the numbers describe the
  application; §3 measures the challenge itself with a clean profile.
- Partner sign-in used the real one-time-code flow. The codes go to
  `@yopmail.com`, a public throwaway inbox, which is where the seeded test
  accounts point.
- Admin has no emailed-code path, which is why §9 needs a password.
