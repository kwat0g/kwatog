import { expect, test } from '../fixtures';

test('customer mobile intake preserves failed evidence and reaches a usable case', async ({ page }) => {
  await page.route('**/api/v1/auth/user', (route) => route.fulfill({ status: 401, json: { message: 'Unauthenticated.' } }));
  await page.route('**/api/v1/b2b/customer/me', (route) => route.fulfill({ status: 200, json: { data: {
    id: 'portal_customer', name: 'Acme Contact', email: 'mobile@acme.test', customer_id: 'customer', customer_name: 'Acme', must_change_password: false,
  } } }));
  await page.route('**/api/v1/b2b/customer/problems/sources**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [{ id: 'delivery_mobile', kind: 'delivery', label: 'DEL-MOBILE', party_name: 'Acme' }] }) }));
  await page.route('**/api/v1/b2b/customer/problems/source-options**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { source: { id: 'delivery_mobile', kind: 'delivery', label: 'DEL-MOBILE' }, type: 'customer', party: { id: 'customer', name: 'Acme' }, lines: [{ id: 'line_mobile', part_number: 'P-1', description: 'Part', unit: 'pcs', expected_quantity: '4.000', received_quantity: '4.000', maximum_quantity: '4.000', lot_number: null, can_return: true }] } }) }));
  await page.route('**/api/v1/b2b/customer/problems', async (route) => {
    if (route.request().method() !== 'POST') return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }) });
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({ data: {
        id: 'case_mobile', case_number: 'CASE-MOBILE', type: 'customer', status: 'submitted', status_label: 'Submitted',
        description: 'Damaged', preferred_resolution: 'credit', resolution: null, resolution_notes: null, expected_date: null,
        created_at: '2026-09-25T00:00:00Z', resolved_at: null, party: { id: 'customer', name: 'Acme' }, owner: null,
        source: { id: 'delivery_mobile', kind: 'delivery', label: 'DEL-MOBILE' }, lines: [], events: [], attachments: [],
      } }),
    });
  });
  await page.route('**/api/v1/b2b/customer/problems/case_mobile', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
    id: 'case_mobile', case_number: 'CASE-MOBILE', type: 'customer', status: 'submitted', status_label: 'Submitted', description: 'Damaged during delivery', preferred_resolution: 'credit', resolution: null, resolution_notes: null, expected_date: null, resolved_at: null,
    party: { id: 'customer', name: 'Acme' }, owner: null, source: { id: 'delivery_mobile', kind: 'delivery', label: 'DEL-MOBILE' }, lines: [{ id: 'mobile_line', source_delivery_item_id: 'line_mobile', source_po_item_id: null, source_grn_item_id: null, product_id: 'product_mobile', item_id: 'item_mobile', product_label: 'P-1 Part', item_label: null, description: 'Part', unit: 'pcs', expected_quantity: '4.000', received_quantity: '4.000', missing_quantity: '0.000', defective_quantity: '1.000', verified_missing_quantity: null, verified_defective_quantity: null, returned_quantity: null, lot_number: null, serial_number: null, reason: 'Damaged during delivery' }], events: [{ id: 'mobile_event', action: 'submitted', message: 'Report submitted.', actor_type: 'customer', actor_name: 'Acme Contact', is_public: true, created_at: '2026-09-25T00:00:00Z' }], attachments: uploadAttempts >= 2 ? [{ id: 'photo', file_name: 'damage.png', mime_type: 'image/png', size: 10, created_at: '2026-09-25T00:00:00Z' }] : [],
  } }) }));
  let uploadAttempts = 0;
  await page.route('**/api/v1/b2b/customer/problems/case_mobile/attachments', (route) => {
    uploadAttempts += 1;
    return route.fulfill(uploadAttempts === 1
      ? { status: 503, json: { message: 'Temporary upload failure.' } }
      : { status: 201, json: { data: { id: 'photo', file_name: 'damage.png' } } });
  });
  await page.goto('/portal/customer/problems/new');
  await page.getByLabel('Search by document number').fill('DEL-MOBILE');
  await expect(page.getByText('DEL-MOBILE')).toBeVisible();
  await page.getByRole('button', { name: /DEL-MOBILE/ }).click();
  await expect(page.getByText('Affected goods')).toBeVisible();
  await page.getByRole('checkbox').first().check();
  await page.getByLabel('Damaged or defective').fill('1');
  await page.getByLabel('Explain the problem').fill('Damaged during delivery');
  await page.locator('input[type=file]').setInputFiles({ name: 'damage.png', mimeType: 'image/png', buffer: Buffer.from('mock image') });
  await expect(page.getByRole('button', { name: 'Submit report' })).toBeVisible();
  await page.getByRole('button', { name: 'Submit report' }).click();
  await expect(page).toHaveURL(/\/portal\/customer\/problems\/case_mobile$/);
  await expect(page.getByRole('heading', { name: 'Reported problem' })).toBeVisible();
  await expect(page.getByText('Damaged during delivery')).toBeVisible();
  await expect(page.getByText('Page not found')).toHaveCount(0);
  await expect(page.getByText('1 file still need uploading')).toBeVisible();
  await page.getByRole('button', { name: 'Retry upload' }).click();
  await expect(page.getByText('Evidence uploaded.')).toBeVisible();
  expect(uploadAttempts).toBe(2);
  await expect(page.getByRole('button', { name: 'Download damage.png' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Retry upload' })).toHaveCount(0);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
  expect(overflow).toBe(false);
  await expect(page.getByText('Evidence uploaded.')).not.toBeVisible({ timeout: 7000 });
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({ path: '/tmp/return-case-mobile.png', fullPage: true });
});
