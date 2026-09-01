/* ==========================================================================
   A browser walks the site and the admin, at two sizes, and reports what it
   finds: pages that error, layouts that scroll sideways, text too small to
   read, controls too small to hit, and anything the console complained about.

     NODE_PATH=/tmp/pw/node_modules node admin/tests/ui-sweep.js

   Nothing here fixes anything — it only looks, so it can be trusted to say
   when something is still wrong.
   ========================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://localhost/manifold';

const SIZES = [
  { name: 'desktop', width: 1440, height: 900, mobile: false },
  { name: 'mobile', width: 390, height: 844, mobile: true },
];

const PUBLIC_PAGES = [
  '/', '/stove', '/tuktuk', '/technology',
  '/contact', '/blog', '/apply-stove', '/apply-tuktuk',
  '/privacy-policy', '/portal/',
];

const ADMIN_PAGES = [
  '/admin/', '/admin/list?type=stove', '/admin/dealers',
  '/admin/distributors', '/admin/stock', '/admin/vouchers',
  '/admin/referrals', '/admin/raffle', '/admin/blog',
  '/admin/settings',
];

const RF_PAGES = ['/rf/', '/rf/history'];

async function signIn(page, email, password) {
  /* a live session redirects the login page away from its own form, so the
     previous role is signed out before the next one signs in */
  await page.goto(BASE + '/admin/logout', { waitUntil: 'domcontentloaded' });
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"]'),
  ]);
  return !page.url().includes('/login');
}

/* everything one page can be wrong about */
async function inspect(page, url, size) {
  const problems = [];

  const consoleErrors = [];
  page.on('console', (m) => {
    if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 120));
  });
  page.on('pageerror', (e) => consoleErrors.push('uncaught: ' + String(e).slice(0, 120)));

  const started = Date.now();
  let response;

  try {
    response = await page.goto(BASE + url, { waitUntil: 'load', timeout: 30000 });
  } catch (err) {
    return { url, size: size.name, ms: Date.now() - started, problems: ['did not load: ' + err.message.slice(0, 80)] };
  }

  const ms = Date.now() - started;

  if (response && response.status() >= 400) {
    problems.push('HTTP ' + response.status());
  }

  const found = await page.evaluate(() => {
    const out = { overflow: null, small: [], tiny: [], overlap: [], missingAlt: 0 };

    /* content wider than the window: the classic mobile break */
    const doc = document.documentElement;
    if (doc.scrollWidth > doc.clientWidth + 1) {
      /* name the widest thing sticking out, so it can be found */
      let worst = null;
      const clipped = (el) => {
        for (let p = el.parentElement; p; p = p.parentElement) {
          const o = getComputedStyle(p).overflowX;
          if (o === 'hidden' || o === 'clip' || o === 'auto' || o === 'scroll') return true;
        }
        return false;
      };

      document.querySelectorAll('body *').forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) return;
        /* something its parent already clips cannot scroll the page */
        if (clipped(el)) return;
        const over = Math.round(r.right - doc.clientWidth);
        if (over > 2 && (!worst || over > worst.over)) {
          worst = { over, tag: el.tagName.toLowerCase(), cls: (el.className || '').toString().slice(0, 40) };
        }
      });
      out.overflow = { by: doc.scrollWidth - doc.clientWidth, worst };
    }

    /* text nobody can read, and targets nobody can hit */
    const seen = new Set();
    document.querySelectorAll('p, span, td, th, li, label, a, button, input, select').forEach((el) => {
      const style = getComputedStyle(el);
      if (style.display === 'none' || style.visibility === 'hidden') return;

      const size = parseFloat(style.fontSize);
      const text = (el.textContent || '').trim();

      if (text && size && size < 11 && !seen.has(text)) {
        seen.add(text);
        out.small.push(Math.round(size) + 'px: ' + text.slice(0, 40));
      }

      if (/^(a|button|input|select)$/i.test(el.tagName)) {
        const r = el.getBoundingClientRect();
        const hit = r.width > 0 && r.height > 0 && (r.height < 28 || r.width < 28);
        if (hit && text) out.tiny.push(Math.round(r.width) + 'x' + Math.round(r.height) + ' ' + text.slice(0, 30));
      }
    });

    document.querySelectorAll('img:not([alt])').forEach(() => { out.missingAlt++; });

    return out;
  });

  if (found.overflow) {
    problems.push('scrolls sideways by ' + found.overflow.by + 'px'
      + (found.overflow.worst ? ' (worst: ' + found.overflow.worst.tag + '.' + found.overflow.worst.cls + ')' : ''));
  }

  if (found.small.length) problems.push('text under 11px: ' + found.small.slice(0, 3).join(' | '));
  if (found.tiny.length > 0) problems.push(found.tiny.length + ' target(s) under 28px: ' + found.tiny.slice(0, 2).join(' | '));
  if (found.missingAlt) problems.push(found.missingAlt + ' image(s) with no alt');
  if (consoleErrors.length) problems.push('console: ' + consoleErrors.slice(0, 2).join(' | '));

  return { url, size: size.name, ms, problems };
}

(async () => {
  const browser = await chromium.launch();
  const rows = [];

  for (const size of SIZES) {
    const context = await browser.newContext({
      viewport: { width: size.width, height: size.height },
      isMobile: size.mobile,
      hasTouch: size.mobile,
      deviceScaleFactor: size.mobile ? 2 : 1,
    });

    const page = await context.newPage();

    for (const url of PUBLIC_PAGES) {
      rows.push(await inspect(page, url, size));
    }

    /* the admin only at desktop, which is where it is used */
    if (!size.mobile) {
      const asRf = await signIn(page, 'rf@manifold.com', 'rf123');

      if (asRf) {
        for (const url of RF_PAGES) rows.push(await inspect(page, url, size));
      } else {
        rows.push({ url: '/rf/', size: size.name, ms: 0, problems: ['could not sign in as R&F'] });
      }

      const pass = process.env.ADMIN_PASS;

      if (pass) {
        const asAdmin = await signIn(page, process.env.ADMIN_EMAIL || 'admin@manifold.com', pass);

        if (asAdmin) {
          for (const url of ADMIN_PAGES) rows.push(await inspect(page, url, size));
        } else {
          rows.push({ url: '/admin/', size: size.name, ms: 0, problems: ['ADMIN_PASS did not sign in'] });
        }
      }
    }

    await context.close();
  }

  await browser.close();

  let bad = 0;

  rows.forEach((r) => {
    const head = '  ' + r.size.padEnd(8) + String(r.ms).padStart(5) + 'ms  ' + r.url;

    if (!r.problems.length) {
      console.log(head);
      return;
    }

    bad++;
    console.log(head + '\n' + r.problems.map((p) => '        ! ' + p).join('\n'));
  });

  const slow = rows.filter((r) => r.ms > 1500);
  if (slow.length) {
    console.log('\n  slowest:');
    slow.sort((a, b) => b.ms - a.ms).slice(0, 5)
      .forEach((r) => console.log('    ' + String(r.ms).padStart(5) + 'ms  ' + r.size + '  ' + r.url));
  }

  console.log('\n  ' + bad + ' of ' + rows.length + ' page loads have something to look at.');
})();
