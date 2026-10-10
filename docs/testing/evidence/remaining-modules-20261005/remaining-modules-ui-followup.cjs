const { chromium } = require(process.cwd() + '/node_modules/@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const output = path.resolve('../docs/testing/evidence/remaining-modules-20261005');

// Read-only business inspection. Only sign-in creates an authentication session.
(async () => {
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [role, email, route] of [
      ['finance', 'finance@ogami.test', '/accounting/balance-sheet'],
      ['impex', 'impex@ogami.test', '/supply-chain/shipments/j16KyzV0Xl'],
      ['warehouse', 'warehouse@ogami.test', '/supply-chain/deliveries'],
    ]) {
      const base = 'http://localhost:18080';
      const context = await browser.newContext({ baseURL: base });
      const headers = { Origin: base, Referer: base + '/', Accept: 'application/json' };
      await context.request.get('/sanctum/csrf-cookie', { headers });
      const xsrf = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
      const login = await context.request.post('/api/v1/auth/sign-in', { headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value || '') }, data: { email, password: 'password' } });
      if (!login.ok()) throw new Error(`${role} sign-in failed: ${login.status()}`);
      const page = await context.newPage();
      for (const width of [1440, 768, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(route, { waitUntil: 'networkidle' });
        for (const theme of ['light', 'dark']) {
          await page.evaluate(theme => document.documentElement.setAttribute('data-theme', theme), theme);
          const measurements = await page.evaluate(() => {
            const visible = element => Boolean(element.getClientRects().length);
            return {
              documentWidth: document.documentElement.scrollWidth,
              viewportWidth: innerWidth,
              headings: [...document.querySelectorAll('h1,h2')].map(element => element.textContent?.trim()),
              amountTables: [...document.querySelectorAll('main table')].filter(table => /Account\s*Amount/i.test(table.textContent)).map(table => {
                const parent = table.parentElement;
                const rect = parent.getBoundingClientRect();
                const cells = [...table.querySelectorAll('tr')].map(row => row.lastElementChild).filter(visible);
                return { width: table.getBoundingClientRect().width, containerWidth: rect.width, hiddenAmountCells: cells.filter(cell => cell.getBoundingClientRect().right > rect.right + 1).length, amountCells: cells.length };
              }),
              overflowElements: [...document.querySelectorAll('main ol, main ol li, main header, main h1, main table')].filter(visible).filter(element => element.getBoundingClientRect().right > innerWidth + 1).map(element => ({ tag: element.tagName, className: element.className, text: element.textContent?.trim().slice(0, 200), right: element.getBoundingClientRect().right })),
              foreground: getComputedStyle(document.body).color,
              background: getComputedStyle(document.body).backgroundColor,
            };
          });
          results.push({ role, route, width, theme, ...measurements });
          if (width === 768 && theme === 'dark') await page.screenshot({ path: path.join(output, `${role}-followup-768-dark.png`) });
        }
      }
      if (role === 'warehouse') {
        const response = await context.request.get('/api/v1/journal-entries', { headers });
        results.push({ role, deniedEndpoint: '/journal-entries', status: response.status() });
      }
      await context.close();
    }
  } finally {
    await browser.close();
    fs.writeFileSync(path.join(output, 'ui-followup-results.json'), JSON.stringify(results, null, 2));
  }
})().catch(error => { console.error(error); process.exit(1); });
