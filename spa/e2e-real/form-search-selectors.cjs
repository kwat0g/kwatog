const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const output = '../.impeccable/review/form-search-selectors';
  fs.mkdirSync(output, { recursive: true });
  const errors = [];
  const sessions = new Map();
  async function session(role) {
    if (sessions.has(role)) return sessions.get(role);
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 1000 } });
    await context.request.get('/sanctum/csrf-cookie', { headers: { Origin: base, Referer: base + '/' } });
    const cookie = (await context.cookies()).find(row => row.name === 'XSRF-TOKEN');
    const response = await context.request.post('/api/v1/auth/sign-in', { data: { email: role + '@ogami.test', password: 'password' }, headers: { Origin: base, Referer: base + '/', 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) } });
    assert(response.ok(), await response.text());
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.url().includes('/api/') && response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
    sessions.set(role, page);
    return page;
  }
  const cases = [
    ['warehouse', '/inventory/stock-adjustments/create', 'Item'],
    ['purchasing', '/purchasing/purchase-requests/create', 'Item'],
    ['production', '/production/work-orders/create', 'Product'],
    ['ppc', '/production/routings/create', 'Finished good'],
    ['customerservice', '/crm/price-agreements/create', 'Product'],
    ['crm', '/crm/sales-orders/create', 'Customer'],
    ['qc', '/quality/inspections/new', 'Product'],
    ['finance', '/accounting/journal-entries/create', 'Account for line 1'],
    ['hr', '/hr/leaves/create', 'Employee'],
    ['hr', '/hr/loans/create', 'Employee'],
    ['admin', '/admin/users/create', 'Department'],
    ['impex', '/supply-chain/deliveries/create', 'Sales order'],
  ];
  try {
    for (const [role, route, label] of cases) {
      const page = await session(role);
      await page.goto(route);
      if (route === '/quality/inspections/new') await page.getByRole('combobox', { name: 'Stage', exact: true }).selectOption('in_process');
      const field = page.getByRole('combobox', { name: label, exact: true }).first();
      await expect(field).toBeEnabled({ timeout: 15000 });
      assert.equal(await field.evaluate(element => element.tagName), 'INPUT');
      await field.click();
      await expect(page.getByRole('listbox')).toBeVisible();
      const option = page.getByRole('listbox').getByRole('option').filter({ hasNotText: /^(Select|Choose|—|Loading)/ }).first();
      await expect(option).toBeVisible({ timeout: 15000 });
      const text = (await option.textContent()).trim();
      await option.click();
      await expect(field).toHaveValue(text);
      const native = page.locator('select').filter({ has: page.locator('option').filter({ hasText: text }) }).first();
      assert(await native.inputValue(), 'Selected record ID is kept in the form');
      if (route === '/admin/users/create') {
        const employee = page.getByRole('combobox', { name: 'Employee', exact: true });
        await expect(employee).toBeEnabled();
        await employee.click();
        await expect(page.getByRole('listbox')).toBeVisible();
        await employee.press('Escape');
      }
      if (route === '/crm/price-agreements/create') {
        const searches = [];
        const listener = request => { if (request.url().includes('/api/v1/crm/products?') && new URL(request.url()).searchParams.has('search')) searches.push(new URL(request.url()).searchParams.get('search')); };
        page.on('request', listener);
        await field.fill('Q');
        await field.fill('QU');
        await field.fill('QUERY-NO-MATCH');
        await expect.poll(() => searches.length, { timeout: 5000 }).toBe(1);
        assert.deepEqual(searches, ['QUERY-NO-MATCH']);
        await field.press('Escape');
        await expect(field).toHaveValue(text);
        page.off('request', listener);
      }
      await page.setViewportSize({ width: 390, height: 844 });
      await field.click();
      await expect(page.getByRole('listbox')).toBeVisible();
      const popup = await page.getByRole('listbox').evaluate(element => {
        const bounds = element.parentElement.getBoundingClientRect();
        return { left: bounds.left, right: bounds.right, width: innerWidth };
      });
      assert(popup.left >= 0 && popup.right <= popup.width, JSON.stringify(popup));
      if (['/purchasing/purchase-requests/create', '/admin/users/create', '/accounting/journal-entries/create'].includes(route)) await page.screenshot({ path: output + '/' + role + '-mobile.png' });
      await field.press('Escape');
      await page.setViewportSize({ width: 1440, height: 1000 });
      console.log(role + ' ' + route + ' passed');
    }
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ forms: cases.length, roles: sessions.size, combined_selectors: true, selection_ids: true, debounce_requests: 1, mobile_popup_bounds: true, browser_errors: errors }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
