const { chromium } = require(process.cwd() + '/node_modules/@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
(async () => {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ baseURL: 'http://localhost:18080', viewport: { width: 1440, height: 900 } });
  const headers = { Origin: 'http://localhost:18080', Referer: 'http://localhost:18080/', Accept: 'application/json' };
  try {
    await context.request.get('/sanctum/csrf-cookie', { headers });
    const token = (await context.cookies()).find(c => c.name === 'XSRF-TOKEN');
    const login = await context.request.post('/api/v1/auth/sign-in', { headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(token.value) }, data: { email: 'finance@ogami.test', password: 'password' } });
    if (!login.ok()) throw Error('Finance sign-in failed: ' + login.status());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const credit = { id: 'browser-credit', credit_note_number: 'CN-RETRY', type: 'customer', type_label: 'Customer', status: 'finalized', status_label: 'Finalized', date: '2026-10-01', subtotal: '1000.00', vat_amount: '0.00', total_amount: '1000.00', applied_amount: '0.00', balance: '1000.00', is_vatable: false, customer: { id: 'browser-customer', name: 'Retry test customer' }, invoice: null, lines: [], applications: [] };
    const payloads = [];
    await page.route('**/api/v1/accounting/credit-notes/browser-credit', route => route.fulfill({ json: { data: credit } }));
    await page.route('**/api/v1/invoices?**', route => route.fulfill({ json: { data: [{ id: 'browser-invoice', invoice_number: 'INV-RETRY', status: 'finalized', balance: '1000.00', total_amount: '1000.00' }], meta: { total: 1, current_page: 1, last_page: 1, per_page: 100 } } }));
    await page.route('**/api/v1/accounting/credit-notes/browser-credit/apply', route => {
      payloads.push(route.request().postDataJSON());
      return route.fulfill({ status: 503, json: { message: 'Simulated lost-response retry boundary' } });
    });
    await page.goto('/accounting/credit-notes/browser-credit', { waitUntil: 'networkidle' });
    await page.getByRole('button', { name: 'Apply to invoice' }).click();
    await page.getByLabel('Open invoice').selectOption('browser-invoice');
    await page.getByLabel('Amount', { exact: false }).fill('100');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await page.waitForFunction(() => [...document.querySelectorAll('button')].some(b => b.textContent.trim() === 'Apply' && !b.disabled));
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await page.waitForTimeout(300);
    if (payloads.length !== 2 || payloads[0].operation_key !== payloads[1].operation_key || !payloads[0].operation_key) throw Error('Retry identity mismatch: ' + JSON.stringify(payloads));
    if (errors.length) throw Error(errors.join('\n'));
    fs.writeFileSync(path.resolve('../docs/testing/evidence/major-integrity-20261006/browser-retry.json'), JSON.stringify({ role: 'finance', headless: true, businessApi: 'intercepted fixture; no business writes', attempts: payloads.length, stableOperationKey: true, payloads, exceptions: errors }, null, 2));
    await page.screenshot({ path: path.resolve('../docs/testing/evidence/major-integrity-20261006/browser-credit-retry.png') });
  } finally { await context.close(); await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
