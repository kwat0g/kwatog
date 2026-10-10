const { chromium } = require(process.cwd() + '/node_modules/@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const output = path.resolve('../docs/testing/evidence/quality-pass-fail-audit-20261006');

// The audit creates authentication sessions, but submits no business mutations.
(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const [role, email] of [['qc', 'qc@ogami.test'], ['admin', 'admin@ogami.test']]) {
      const context = await browser.newContext({ baseURL: base });
      const headers = { Origin: base, Referer: base + '/', Accept: 'application/json' };
      await context.request.get('/sanctum/csrf-cookie', { headers });
      const cookie = (await context.cookies()).find(row => row.name === 'XSRF-TOKEN');
      const login = await context.request.post('/api/v1/auth/sign-in', {
        headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(cookie?.value || '') },
        data: { email, password: 'password' },
      });
      if (!login.ok()) { results.push({ role, loginStatus: login.status() }); await context.close(); continue; }
      const get = async endpoint => {
        const response = await context.request.get('/api/v1' + endpoint, { headers });
        return { status: response.status(), body: await response.json().catch(() => ({})) };
      };
      const routes = ['/quality/inspection-specs', '/quality/inspections'];
      if (role === 'admin') {
        const specs = await get('/quality/inspection-specs?per_page=100');
        const spec = specs.body.data?.find(row => row.is_active);
        if (spec?.id) routes.push('/quality/inspection-specs/spec/' + spec.id);
      } else {
        for (const stage of ['incoming', 'in_process', 'outgoing', 'customer_return', 'supplier_return']) {
          const list = await get('/quality/inspections?stage=' + stage + '&per_page=100');
          const rows = Array.isArray(list.body.data) ? list.body.data : [];
          const row = rows.find(item => ['draft', 'in_progress'].includes(item.status)) ?? rows[0];
          results.push({ role, stage, listStatus: list.status, recordsReturned: rows.length,
            selectedStatus: row?.status ?? null, selectedMode: row?.inspection_mode ?? null });
          if (row?.id) routes.push('/quality/inspections/' + row.id);
        }
      }
      const page = await context.newPage();
      let failures = [], exceptions = [];
      page.on('response', response => {
        if (response.url().includes('/api/') && response.status() >= 400)
          failures.push({ path: new URL(response.url()).pathname, status: response.status() });
      });
      page.on('pageerror', error => exceptions.push(error.message));
      for (const route of routes) {
        for (const width of [1440, 390]) {
          failures = []; exceptions = [];
          await page.setViewportSize({ width, height: 900 });
          await page.goto(route, { waitUntil: 'networkidle', timeout: 30000 });
          const scan = await page.evaluate(() => {
            const main = document.querySelector('main') || document.body;
            const visible = element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden';
            const inputs = [...main.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(visible);
            const buttons = [...main.querySelectorAll('button')].filter(visible);
            const name = element => element.getAttribute('aria-label') || [...(element.labels || [])].map(label => label.innerText).join(' ') || '';
            return {
              viewportWidth: innerWidth, documentWidth: document.documentElement.scrollWidth,
              numericInputs: inputs.filter(element => element.type === 'number').map(element => ({ name: name(element), value: element.value })),
              nominalAndLimitInputs: inputs.filter(element => /nominal|tolerance/i.test(name(element))).map(element => name(element)),
              unnamedInputs: inputs.filter(element => !name(element) && !element.getAttribute('aria-labelledby')).map(element => ({ type: element.type, placeholder: element.placeholder })),
              decisionButtons: buttons.filter(element => /^(PASS|FAIL)(?:\s|$)/.test(element.innerText)).map(element => ({ name: name(element), disabled: element.disabled, height: element.getBoundingClientRect().height })),
              headings: [...main.querySelectorAll('h1,h2')].map(element => element.textContent.trim()),
              numericLanguage: [...new Set(main.innerText.match(/Nominal|Tolerance|Cp \/ Cpk|SPC capability indices|Save measurements/g) || [])],
              text: main.innerText.slice(0, 5000),
            };
          });
          results.push({ role, route, width, failures: [...failures], exceptions: [...exceptions], ...scan });
          await page.screenshot({ path: path.join(output, role + '-' + route.replaceAll('/', '_') + '-' + width + '.png'), fullPage: false });
        }
      }
      await context.close();
    }
  } finally {
    fs.writeFileSync(path.join(output, 'browser-results.json'), JSON.stringify(results, null, 2));
    await browser.close();
  }
  console.log(JSON.stringify({ visits: results.filter(row => row.route).length, failures: results.filter(row => row.failures?.length || row.exceptions?.length), loginFailures: results.filter(row => row.loginStatus) }, null, 2));
})().catch(error => { console.error(error); process.exit(1); });
