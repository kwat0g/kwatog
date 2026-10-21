import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

for (const form of [
  { path: '/maintenance/work-orders/create', endpoint: '**/api/v1/maintenance/work-orders/options', message: 'Maintenance work order options could not be loaded', button: 'Create work order' },
]) {
  test(`${form.path} names a failed prerequisite and recovers`, async ({ page }) => {
    let unavailable = true;
    await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
    await page.route(form.endpoint, route => route.fulfill(unavailable
      ? { status: 503, json: { message: 'Prerequisite unavailable' } }
      : { json: { data: {} } }));
    await loginAs(page, 'admin', form.path);
    await expect(page.getByRole('alert').filter({ hasText: form.message })).toBeVisible();
    await expect(page.getByRole('button', { name: form.button, exact: true })).toBeDisabled();
    unavailable = false;
    await page.getByRole('button', { name: 'Retry options', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: form.message })).toBeHidden();
  });
}
