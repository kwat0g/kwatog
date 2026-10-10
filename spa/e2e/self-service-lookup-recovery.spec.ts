import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('employee leave form reports failed policy options and retries', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  let unavailable = true;
  await page.route('**/leaves/requests/options**', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Leave policy unavailable' } }
    : { json: { data: { half_day_periods: [], holiday_dates: [] } } }));
  await loginAs(page, 'employee', '/self-service/leave');
  await page.getByRole('button', { name: 'New request', exact: true }).first().click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('alert').filter({ hasText: 'Leave options could not be loaded' })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Submit request', exact: true })).toBeDisabled();
  unavailable = false;
  await dialog.getByRole('button', { name: 'Retry options' }).click();
  await expect(dialog.getByRole('alert').filter({ hasText: 'Leave options could not be loaded' })).toBeHidden();
});

test('employee loan preview failure explains recovery and allows server-calculated submission', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/hr/self-service/loans', route => route.fulfill({ json: { data: {
    active: [], history: [], max_pay_periods: 12, loan_types: [{ value: 'company_loan', label: 'Company loan' }],
  } } }));
  let unavailable = true;
  await page.route('**/loans/preview-amortization', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Preview unavailable' } }
    : { json: { data: [{ period: 1, amount: '100', remaining_after: '0' }] } }));
  await loginAs(page, 'employee', '/self-service/loans');
  await page.getByRole('button', { name: 'Apply for a loan', exact: true }).first().click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('spinbutton', { name: 'Amount', exact: true }).fill('100');
  await dialog.getByRole('spinbutton', { name: 'Periods (months)', exact: true }).fill('1');
  await expect(dialog.getByRole('alert').filter({ hasText: 'Amortization preview could not be loaded' })).toBeVisible();
  unavailable = false;
  await dialog.getByRole('button', { name: 'Retry preview' }).click();
  await expect(dialog.getByRole('alert').filter({ hasText: 'Amortization preview could not be loaded' })).toBeHidden();
});
