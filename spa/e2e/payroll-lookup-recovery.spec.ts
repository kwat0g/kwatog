import { expect, test } from '@playwright/test';
import { loginAs } from './helpers-extended';

test('payroll bank review failure blocks generation and offers retry', async ({ page }) => {
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/payroll-periods/bank-file/options', route => route.fulfill({ json: { data: { default_format: 'generic', formats: [{ value: 'generic', label: 'Generic CSV' }] } } }));
  await page.route('**/payroll-periods/period-1', route => route.fulfill({ json: { data: {
    id: 'period-1', label: 'October payroll', period_start: '2026-10-01', period_end: '2026-10-15', payroll_date: '2026-10-16',
    status: 'finalized', status_label: 'Finalized', employee_count: 0, is_first_half: true,
    is_thirteenth_month: false, is_company_wide: true, disbursement_proofs: [], departments: [], positions: [],
  } } }));
  let unavailable = true;
  await page.route('**/payroll-periods/period-1/bank-file/preview**', route => route.fulfill(unavailable
    ? { status: 503, json: { message: 'Bank review unavailable' } }
    : { json: { data: { unbankable_count: 0, unbankable_amount: '0', unbankable_sample: [] } } }));
  let proofUnavailable = true;
  await page.route('**/payroll-periods/period-1/disbursement-proofs/options', route => route.fulfill(proofUnavailable
    ? { status: 503, json: { message: 'Proof options unavailable' } }
    : { json: { data: { proof_types: [{ value: 'bank_confirmation', label: 'Bank confirmation' }] } } }));
  let rowsUnavailable = true;
  await page.route('**/payroll-periods/period-1/payrolls**', route => route.fulfill(rowsUnavailable
    ? { status: 503, json: { message: 'Employee payroll unavailable' } }
    : { json: { data: [], meta: { current_page: 1, last_page: 1, per_page: 50, total: 0 } } }));
  await loginAs(page, 'admin', '/payroll/periods/period-1');
  await expect(page.getByRole('alert').filter({ hasText: 'Bank file review could not be loaded' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Generate & download' })).toBeDisabled();
  unavailable = false;
  await page.getByRole('button', { name: 'Retry bank review' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Bank file review could not be loaded' })).toBeHidden();
  await expect(page.getByRole('button', { name: 'Generate & download' })).toBeEnabled();
  await page.getByRole('button', { name: 'Upload Proof', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('alert').filter({ hasText: 'Proof types could not be loaded' })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Upload', exact: true })).toBeDisabled();
  proofUnavailable = false;
  await dialog.getByRole('button', { name: 'Retry proof types' }).click();
  await expect(dialog.getByRole('alert').filter({ hasText: 'Proof types could not be loaded' })).toBeHidden();
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Employee payroll could not be loaded' })).toBeVisible();
  rowsUnavailable = false;
  await page.getByRole('button', { name: 'Retry employee payroll' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Employee payroll could not be loaded' })).toBeHidden();
});
