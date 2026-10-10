import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

for (const form of [
  { path: '/hr/leaves/create', endpoint: '**/api/v1/leaves/requests/options', message: 'Leave options could not be loaded', button: 'Submit request' },
  { path: '/hr/loans/create', endpoint: '**/api/v1/loans/types', message: 'Loan options could not be loaded', button: 'Submit request' },
  { path: '/assets/create', endpoint: '**/api/v1/assets/options', message: 'Asset options could not be loaded', button: 'Create asset' },
]) {
  test(`${form.path} reports failed options and recovers`, async ({ page }) => {
    let unavailable = true;
    await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
    await page.route(form.endpoint, route => route.fulfill(unavailable
      ? { status: 503, json: { message: 'Options unavailable' } }
      : { json: { data: form.path.includes('loans') ? [] : {} } }));
    await loginAs(page, 'admin', form.path);
    await expect(page.getByRole('alert').filter({ hasText: form.message })).toBeVisible();
    await expect(page.getByRole('button', { name: form.button, exact: true })).toBeDisabled();
    unavailable = false;
    await page.getByRole('button', { name: 'Retry options', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: form.message })).toBeHidden();
  });
}
