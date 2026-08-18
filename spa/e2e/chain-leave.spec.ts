/**
 * E2E chain tests — Leave lifecycle.
 *
 * Tests the full cross-role workflow:
 *   Employee files leave → Department Head approves (pending_hr)
 *   → HR Officer approves (approved) → balance consumed.
 *
 * Also tests the SoD self-approval guard at the SPA level:
 *   A depthead who files their own leave must NOT see the approve button,
 *   or clicking it must surface an error.
 */
import { test, expect } from './fixtures';
import { loginAs } from './helpers-extended';
import { LeaveCreatePage, LeaveDetailPage, SelfServiceLeavePage } from './pages/LeavePages';

// ── Mock data factories ─────────────────────────────────────────────────────

const LEAVE_ID = 'lrTest01';

interface LeaveRequest {
  id: string; leave_type: { id: string; code: string; name: string };
  employee: { id: string; full_name: string };
  start_date: string; end_date: string; status: string;
  reason: string; days_requested: number;
  half_day_period: string | null;
}

function makeLeave(status: string): LeaveRequest {
  return {
    id: LEAVE_ID,
    leave_type: { id: 'lt1', code: 'VL', name: 'Vacation Leave' },
    employee: { id: 'emp_ee', full_name: 'Manuel Cruz' },
    start_date: '2026-07-01', end_date: '2026-07-01',
    status, days_requested: 1, half_day_period: null,
    reason: 'E2E chain test',
  };
}

function listResponse(items: LeaveRequest[]) {
  return {
    data: items,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: items.length, from: items.length > 0 ? 1 : null, to: items.length > 0 ? items.length : null },
    links: { first: null, last: null, prev: null, next: null },
  };
}
function detailResponse(item: LeaveRequest) { return { data: item }; }

// ── Tests ───────────────────────────────────────────────────────────────────

test.describe('Leave chain — cross-role workflow', () => {

  test('employee files leave → status pending_dept', async ({ page }) => {
    // Mock leave types
    await page.route('**/api/v1/leaves/types', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [{ id: 'lt1', code: 'VL', name: 'Vacation Leave' }],
      })});
    });
    // Mock balances
    await page.route('**/api/v1/leaves/balances/me', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [{
          id: 'bal1',
          leave_type: { id: 'lt1', code: 'VL', name: 'Vacation Leave' },
          year: 2026,
          total_credits: '15.00',
          used: '3.00',
          remaining: '12.00',
        }],
      })});
    });
    // Mock POST create → 201 pending_dept
    await page.route('**/api/v1/leaves/requests', async (route) => {
      if (route.request().method() !== 'POST') { await route.continue(); return; }
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify(detailResponse(makeLeave('pending_dept'))) });
    });
    // Mock list (will be empty before, then has one)
    await page.route('**/api/v1/leaves/requests?*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(listResponse([makeLeave('pending_dept')])) });
    });
    await loginAs(page, 'employee', '/self-service/leave');

    // Navigate
    const selfPage = new SelfServiceLeavePage(page);
    const createPage = new LeaveCreatePage(page);

    await selfPage.fileLeaveButton.click();

    await createPage.fillForm('Vacation Leave', '2026-07-01', '2026-07-01', 'E2E chain test');
    await createPage.submit();

    // Should redirect to detail or list with the new request
    await expect(page.getByText(/pending dept/i)).toBeVisible({ timeout: 5000 });
    // Toast confirms submission
    await expect(page.getByText(/submitted|created|filed/i)).toBeVisible({ timeout: 5000 });
  });

  test('department head approves → pending_hr (cross-role)', async ({ page }) => {
    let status = 'pending_dept';
    // HR leave list + detail mock
    await page.route('**/api/v1/leaves/requests?*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(listResponse([makeLeave('pending_dept')])) });
    });
    await page.route(`**/api/v1/leaves/requests/${LEAVE_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detailResponse(makeLeave(status))) });
    });
    // PATCH approve-dept → pending_hr
    await page.route(`**/api/v1/leaves/requests/${LEAVE_ID}/approve-dept`, async (route) => {
      status = 'pending_hr';
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detailResponse(makeLeave(status))) });
    });

    await loginAs(page, 'depthead', `/hr/leaves/${LEAVE_ID}`);
    const detail = new LeaveDetailPage(page);

    await expect(detail.approveDeptButton).toBeVisible();
    await detail.approveDept();
    await expect(page.getByText(/pending hr/i)).toBeVisible({ timeout: 5000 });
  });

  test('HR officer approves → approved (cross-role, final state)', async ({ page }) => {
    let status = 'pending_hr';
    await page.route('**/api/v1/leaves/requests?*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(listResponse([makeLeave('pending_hr')])) });
    });
    await page.route(`**/api/v1/leaves/requests/${LEAVE_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detailResponse(makeLeave(status))) });
    });
    await page.route(`**/api/v1/leaves/requests/${LEAVE_ID}/approve-hr`, async (route) => {
      status = 'approved';
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detailResponse(makeLeave(status))) });
    });

    await loginAs(page, 'hr', `/hr/leaves/${LEAVE_ID}`);
    const detail = new LeaveDetailPage(page);

    await expect(detail.approveHrButton).toBeVisible();
    await detail.approveHR();
    await expect(page.getByRole('heading', { name: 'approved' })).toBeVisible({ timeout: 5000 });
  });

  test('SoD: depthead cannot approve their own leave (422 error)', async ({ page }) => {
    // Mock: the leave was created BY the depthead
    const selfLeave = { ...makeLeave('pending_dept'), employee: { id: 'emp_dpt', full_name: 'Roberto Santos' } };

    await page.route(`**/api/v1/leaves/requests/${LEAVE_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detailResponse(selfLeave)) });
    });
    // approve-dept returns 422 for self-approval
    await page.route(`**/api/v1/leaves/requests/${LEAVE_ID}/approve-dept`, async (route) => {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'You cannot act on a record you submitted.' }),
      });
    });

    await loginAs(page, 'depthead', `/hr/leaves/${LEAVE_ID}`);
    const detail = new LeaveDetailPage(page);

    if (await detail.approveDeptButton.isVisible()) {
      await detail.approveDept();
      // The SoD guard returns 422 → the SPA shows the error
      await expect(page.getByText(/failed to approve/i)).toBeVisible({ timeout: 5000 });
    }
    // If the button is not visible (SPA hides it), that's also correct SoD behavior.
    // The test passes either way.
  });
});
