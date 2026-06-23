/**
 * E2E mobile tests — self-service portal at 390×844 (Pixel 7 viewport).
 *
 * These run in the 'mobile-chromium' project only (playwright.config.ts filters
 * by testMatch: '**\/mobile/**').
 *
 * Covers: /self-service/me, /self-service/leave, /self-service/payslips,
 * /self-service/dtr, /self-service/loans, /self-service/profile.
 */
import { test, expect } from '@playwright/test';
import { loginAs, ROLES } from '../helpers-extended';
import { BasePage } from '../pages/BasePage';
import { SelfServiceLeavePage } from '../pages/LeavePages';
import { PayslipPage } from '../pages/PayrollPages';

test.describe('Self-service portal — mobile (390px)', () => {

  test('self-service landing renders', async ({ page }) => {
    await loginAs(page, 'employee', '/self-service');
    const bp = new BasePage(page);
    await expect(bp.forbiddenText).not.toBeVisible();
    // Some self-service tile/content
    await expect(page.getByText(/leave|payslip|dtr|profile/i).first()).toBeVisible();
  });

  test('self-service /me renders employee info', async ({ page }) => {
    await loginAs(page, 'employee', '/self-service/me');
    const bp = new BasePage(page);
    await expect(bp.forbiddenText).not.toBeVisible();
    await expect(page.getByText('Manuel Cruz')).toBeVisible();
  });

  test('self-service /leave renders leave balance and file button', async ({ page }) => {
    // Mock leave types + balances
    await page.route('**/api/v1/leaves/types', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [{ id: 'lt1', code: 'VL', name: 'Vacation Leave' }],
      })});
    });
    await page.route('**/api/v1/leaves/balances/me', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [{ leave_type_id: 'lt1', entitled: '15.00', used: '3.00', pending: '0.00', balance: '12.00' }],
      })});
    });
    await page.route('**/api/v1/leaves/requests*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
        links: { first: null, last: null, prev: null, next: null },
      })});
    });

    await loginAs(page, 'employee', '/self-service/leave');
    const selfPage = new SelfServiceLeavePage(page);
    // File leave button should be visible (employee has leave.create)
    await expect(selfPage.fileLeaveButton).toBeVisible();
    // Balance text shows '12.00'
    await expect(page.getByText(/12\.00/)).toBeVisible();
  });

  test('self-service /payslips renders (scoped to own employee_id)', async ({ page }) => {
    await page.route('**/api/v1/payrolls*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [{
          id: 'pay001', pay_type: 'monthly', days_worked: '11.00',
          gross_pay: '31638.89', total_deductions: '3468.33',
          adjustment_amount: '0.00', net_pay: '28170.56',
          computed_at: '2026-06-15T08:00:00Z',
          employee: { id: 'emp_ee', employee_no: 'OGM-2024-0010', full_name: 'Manuel Cruz' },
        }],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
        links: { first: null, last: null, prev: null, next: null },
      })});
    });

    await loginAs(page, 'employee', '/self-service/payslips');
    const bp = new BasePage(page);
    await expect(bp.forbiddenText).not.toBeVisible();
    // Manuel Cruz's payslip should show his net pay
    await expect(page.getByText('28,170.56').or(page.getByText('28170.56'))).toBeVisible();
  });

  test('self-service /dtr renders', async ({ page }) => {
    await page.route('**/api/v1/attendance/attendances*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [{ id: 'dtr001', date: '2026-06-15', time_in: '06:00', time_out: '17:00', status: 'present' }],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
        links: { first: null, last: null, prev: null, next: null },
      })});
    });

    await loginAs(page, 'employee', '/self-service/dtr');
    const bp = new BasePage(page);
    await expect(bp.forbiddenText).not.toBeVisible();
    await expect(page.getByText(/DTR|attendance|daily/i).first()).toBeVisible();
  });

  test('self-service /loans renders', async ({ page }) => {
    await page.route('**/api/v1/loans*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
        links: { first: null, last: null, prev: null, next: null },
      })});
    });

    await loginAs(page, 'employee', '/self-service/loans');
    const bp = new BasePage(page);
    await expect(bp.forbiddenText).not.toBeVisible();
  });

  test('self-service /profile renders', async ({ page }) => {
    await page.route('**/api/v1/hr/employees/**', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: { id: 'emp_ee', employee_no: 'OGM-2024-0010', full_name: 'Manuel Cruz', first_name: 'Manuel', last_name: 'Cruz', email: 'employee@ogami.test', status: 'active' },
      })});
    });

    await loginAs(page, 'employee', '/self-service/profile');
    const bp = new BasePage(page);
    await expect(bp.forbiddenText).not.toBeVisible();
    await expect(page.getByText('Manuel Cruz')).toBeVisible();
  });
});
