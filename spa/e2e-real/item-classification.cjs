const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

(async () => {
  const base = 'http://localhost:18080';
  const browser = await chromium.launch({ headless: true });
  const output = '../.impeccable/review/item-bom-forms';
  fs.mkdirSync(output, { recursive: true });
  const errors = [];
  async function session(email) {
    const context = await browser.newContext({ baseURL: base, viewport: { width: 1440, height: 1000 } });
    await context.request.get('/sanctum/csrf-cookie', { headers: { Origin: base, Referer: base + '/' } });
    async function api(method, route, data) {
      const cookie = (await context.cookies()).find(row => row.name === 'XSRF-TOKEN');
      return context.request.fetch('/api/v1' + route, { method, data, headers: { Origin: base, Referer: base + '/', 'X-XSRF-TOKEN': decodeURIComponent(cookie.value) } });
    }
    const response = await api('POST', '/auth/sign-in', { email, password: 'password' });
    assert(response.ok(), await response.text());
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.url().includes('/api/') && response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
    return { page, api };
  }
  async function success(actor, method, route, data) {
    const response = await actor.api(method, route, data);
    assert(response.ok(), await response.text());
    return (await response.json()).data;
  }
  async function fits(page) {
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No document-level horizontal overflow');
  }
  try {
    const admin = await session('admin@ogami.test');
    const warehouse = await session('warehouse@ogami.test');
    const planner = await session('ppc@ogami.test');
    const tag = String(Date.now()).slice(-8);
    const page = warehouse.page;
    await page.goto('/inventory/items');
    await page.getByRole('button', { name: 'Categories', exact: true }).click();
    const manager = page.getByRole('dialog', { name: 'Item Categories', exact: true });
    await manager.getByRole('button', { name: 'New category', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'New category', exact: true });
    await dialog.getByLabel(/^Name/).fill('Classification ' + tag);
    await dialog.getByRole('combobox', { name: 'Inventory type' }).selectOption('spare_part');
    await fits(page);
    await page.screenshot({ path: output + '/category-desktop.png' });
    await page.setViewportSize({ width: 390, height: 844 });
    await fits(page);
    await page.screenshot({ path: output + '/category-mobile.png' });
    const saved = page.waitForResponse(response => response.url().endsWith('/api/v1/inventory/item-categories') && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    const response = await saved;
    assert.equal(response.status(), 201, await response.text());
    const root = (await response.json()).data;
    assert.equal(root.item_type, 'spare_part');
    await expect(dialog).toHaveCount(0);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await manager.getByRole('button', { name: 'New category', exact: true }).click();
    await dialog.getByLabel(/^Name/).fill('Classification child ' + tag);
    await dialog.getByRole('combobox', { name: 'Parent (optional)' }).fill(root.name);
    await page.getByRole('option', { name: root.name, exact: true }).click();
    await expect(dialog.getByRole('combobox', { name: 'Inventory type' })).toHaveCount(0);
    await expect(dialog.getByText('Inherits Spare Part from ' + root.name + '.')).toBeVisible();
    const childSaved = page.waitForResponse(response => response.url().endsWith('/api/v1/inventory/item-categories') && response.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    const childResponse = await childSaved;
    assert.equal(childResponse.status(), 201, await childResponse.text());
    const child = (await childResponse.json()).data;
    assert.equal(child.item_type, 'spare_part');
    assert.equal(child.parent_id, root.id);
    const payload = { code: 'CLASS-' + tag, name: 'Category classification ' + tag, category_id: child.id, unit_of_measure: 'PCS', standard_cost: '2', reorder_method: 'fixed_quantity', reorder_point: '0', safety_stock: '0', lead_time_days: 0 };
    const mismatch = await warehouse.api('POST', '/inventory/items', { ...payload, item_type: 'raw_material' });
    assert.equal(mismatch.status(), 422);
    const item = await success(warehouse, 'POST', '/inventory/items', payload);
    assert.equal(item.item_type, 'spare_part');
    const changed = await warehouse.api('PUT', '/inventory/item-categories/' + root.id, { item_type: 'raw_material' });
    assert.equal(changed.status(), 422);
    const cycle = await warehouse.api('PUT', '/inventory/item-categories/' + root.id, { parent_id: child.id });
    assert.equal(cycle.status(), 422);
    const denied = await planner.api('POST', '/inventory/item-categories', { name: 'Unauthorized ' + tag, item_type: 'raw_material' });
    assert.equal(denied.status(), 403);
    await page.goto('/inventory/items/' + item.id + '/edit');
    await expect(page.getByRole('combobox', { name: /^Item type/ })).toHaveCount(0);
    await expect(page.getByText('Spare Part · determined by category')).toBeVisible();
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ roles: ['warehouse', 'ppc', 'admin'], root_setup: true, inherited_child: true, inferred_item_type: true, mismatch_rejected: true, category_reclassification_rejected: true, hierarchy_cycle_rejected: true, unauthorized_setup_rejected: true, edit_single_category: true, desktop_mobile_overflow: false, browser_errors: errors }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
