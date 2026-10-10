import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('material issue reports a failed item catalogue and retries without losing entered reference', async ({ page }) => {
  let unavailable = true;
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/inventory/material-issues/options*', route => route.fulfill({
    json: { data: { work_orders: [], sources: [], reservations: [], reasons: [] } },
  }));
  await page.route('**/api/v1/inventory/items?*', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Item catalogue unavailable' } }
    : { json: { data: [], meta: { last_page: 1 } } }));
  await loginAs(page, 'admin', '/inventory/material-issues/create');
  await expect(page.getByRole('alert').filter({ hasText: 'Inventory options could not be loaded' })).toBeVisible();
  await page.getByLabel(/reference/i).fill('Production request');
  await expect(page.getByRole('button', { name: 'Create issue' })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Inventory options could not be loaded' })).toBeHidden();
  await expect(page.getByLabel(/reference/i)).toHaveValue('Production request');
});
