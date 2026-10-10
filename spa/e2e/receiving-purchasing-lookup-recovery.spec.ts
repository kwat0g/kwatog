import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

for (const form of [
  { path: '/inventory/grn/create', endpoint: '**/api/v1/inventory/grn/receivable-purchase-orders', message: 'Receiving options could not be loaded', button: 'Create GRN' },
  { path: '/purchasing/purchase-requests/create', endpoint: '**/api/v1/purchasing/purchase-requests/options', message: 'Purchase request options could not be loaded', button: 'Submit for approval' },
]) {
  test(`${form.path} reports failed prerequisite lookup and permits retry`, async ({ page }) => {
    let unavailable = true;
    await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
    await page.route('**/api/v1/inventory/items?*', route => route.fulfill({ json: { data: [], meta: { last_page: 1 } } }));
    await page.route(form.endpoint, route => route.fulfill(unavailable
      ? { status: 503, json: { message: 'Prerequisite unavailable' } }
      : form.path.includes('grn') ? { json: { data: [], meta: { last_page: 1 } } }
        : { json: { data: { priorities: [{ value: 'normal', label: 'Normal' }] } } }));
    await loginAs(page, 'admin', form.path);
    await expect(page.getByRole('alert').filter({ hasText: form.message })).toBeVisible();
    await expect(page.getByRole('button', { name: form.button, exact: true })).toBeDisabled();
    unavailable = false;
    await page.getByRole('button', { name: 'Retry options', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: form.message })).toBeHidden();
  });
}
