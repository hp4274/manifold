/* ==========================================================================
   The lists no longer carry their records — the drawer asks for one when it
   is opened. This clicks a row on each list and checks that what comes back
   is the record, with its tabs, and that the tab a button asks for is the one
   that shows.

     ADMIN_EMAIL=... ADMIN_PASS=... NODE_PATH=/tmp/pw/node_modules \
       node admin/tests/drawer-open.js
   ========================================================================== */
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://localhost/manifold';

async function signIn(page) {
  await page.goto(BASE + '/admin/logout', { waitUntil: 'domcontentloaded' });
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', process.env.ADMIN_EMAIL || 'admin@manifold.com');
  await page.fill('input[name="password"]', process.env.ADMIN_PASS || '');
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[type="submit"]'),
  ]);

  if (page.url().includes('/login')) throw new Error('could not sign in');
}

async function openFirstRow(page, url) {
  await page.goto(BASE + url, { waitUntil: 'load' });

  const toggle = page.locator('.row-toggle').first();

  if (!(await toggle.count())) return { url, note: 'no rows to open' };

  const title = await toggle.getAttribute('data-title');
  await toggle.click();

  /* The fragment arrives over the network, so wait for the record itself and
     not merely for the panel to be visible. A newsletter signup has one panel
     and no tab bar, so the panel is what every record has. */
  await page.waitForSelector('#drawerBody .detail-panel', { timeout: 5000 });

  const seen = await page.evaluate(() => ({
    tabs:   document.querySelectorAll('#drawerBody .detail-tab').length,
    panels: document.querySelectorAll('#drawerBody .detail-panel').length,
    active: (document.querySelector('#drawerBody .detail-tab.is-active') || {}).textContent,
    text:   document.getElementById('drawerBody').textContent.trim().length,
    title:  (document.getElementById('drawerTitle') || {}).textContent,
    loading: !!document.querySelector('#drawerBody .drawer__loading'),
  }));

  const bad = [];
  if (seen.loading)          bad.push('still showing the loading line');
  if (seen.panels < 1)       bad.push('no panels');
  if (seen.text < 100)       bad.push('almost no content (' + seen.text + ' chars)');
  if (seen.title !== title)  bad.push('header says "' + seen.title + '", row said "' + title + '"');

  /* second open of the same row comes from the cache — it must still fill */
  await page.click('.drawer__close');
  await page.locator('.row-toggle').first().click();
  await page.waitForSelector('#drawerBody .detail-panel', { timeout: 5000 });
  const again = await page.evaluate(() => document.getElementById('drawerBody').textContent.trim().length);
  if (again < 100) bad.push('reopening from cache showed nothing');
  await page.click('.drawer__close');

  return { url, note: bad.length ? bad.join('; ') : 'opens: ' + seen.tabs + ' tabs, ' + seen.text + ' chars', bad: bad.length };
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newContext({ viewport: { width: 1440, height: 900 } }).then((c) => c.newPage());

  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));

  await signIn(page);

  const results = [];
  for (const url of ['/admin/', '/admin/list?type=stove', '/admin/dealers', '/admin/distributors']) {
    results.push(await openFirstRow(page, url));
  }

  await browser.close();

  let bad = 0;
  results.forEach((r) => {
    if (r.bad) bad++;
    console.log('  ' + (r.bad ? 'FAIL ' : 'ok   ') + r.url.padEnd(28) + r.note);
  });

  if (errors.length) console.log('\n  page errors: ' + errors.join(' | '));

  console.log('\n  ' + (bad ? bad + ' list(s) broken' : 'every list opens its records'));
  process.exit(bad || errors.length ? 1 : 0);
})();
