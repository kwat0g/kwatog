/**
 * E2E chain tests — Leave lifecycle.
 *
 * Tests the full cross-role workflow:
 *   Employee files leave → Department Head approves (pending_hr)
 *   → HR Officer approves (approved) → balance consumed.
 *
 * Also tests the SoD self-approval guard: the SPA leaves the Approve button on
 * screen for a request the approver filed themselves, because the guard is
 * server-side, so the refusal has to arrive as a readable message.
 */
import { test, expect } from './fixtures';
import type { Page } from './fixtures';
import { loginAs } from './helpers-extended';
import { LeaveCreatePage, LeaveDetailPage, SelfServiceLeavePage } from './pages/LeavePages';

// ── Mock data factories ─────────────────────────────────────────────────────

const LEAVE_ID = 'lrTest01';
const LEAVE_NO = 'LR-202607-0045';

interface LeaveRequest {
  id: string; leave_request_no: string;
  leave_type: { id: string; code: string; name: string };
  employee: { id: string; full_name: string };
  start_date: string; end_date: string; status: string; status_label: string;
  reason: string; days_requested: number;
  half_day_period: string | null;
}

// `status_label` is what LeaveRequestResource sends and what every list chip
// renders — LeaveRequestStatus::label(), e.g. 'Pending department head'. Omitting
// it left the chip falling back to the raw enum 'pending_dept', which no user
// ever sees, so an assertion written against the real wording found nothing.
const STATUS_LABELS: Record<string, string> = {
  pending_dept: 'Pending department head',
  pending_hr: 'Pending HR approval',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

function makeLeave(status: string): LeaveRequest {
  return {
    id: LEAVE_ID,
    leave_request_no: LEAVE_NO,
    leave_type: { id: 'lt1', code: 'VL', name: 'Vacation Leave' },
    employee: { id: 'emp_ee', full_name: 'Manuel Cruz' },
    start_date: '2026-07-01', end_date: '2026-07-01',
    status, status_label: STATUS_LABELS[status] ?? status,
    days_requested: 1, half_day_period: null,
    reason: 'E2E chain test',
  };
}

/**
 * `GET /leaves/requests/options`, which the DETAIL page — not the list — uses to
 * label its status chip.
 *
 * Two label sources exist for one enum and they do not agree: the resource sends
 * `LeaveRequestStatus::label()` ('Pending HR approval'), while this endpoint
 * builds `ucfirst` + underscore-strip ('Pending hr'). Leaving it unmocked made
 * the chip fall through to `req.status.replace('_',' ')` — 'pending hr', lower
 * case — which differs from the real label by one character, so a `/pending hr/i`
 * assertion could not tell the rendered label from the fallback. Mock it and
 * assert the exact string.
 */
const OPTIONS_STATUS_LABELS: Record<string, string> = {
  pending_dept: 'Pending dept',
  pending_hr: 'Pending hr',
  approved: 'Approved',
  rejected: 'Rejected',
  cancelled: 'Cancelled',
};

async function mockLeaveOptions(page: Page): Promise<void> {
  await page.route('**/api/v1/leaves/requests/options', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
      statuses: Object.entries(OPTIONS_STATUS_LABELS).map(([value, label]) => ({ value, label })),
      half_day_periods: [
        { value: 'none', label: 'Full day' },
        { value: 'am', label: 'Morning (AM half-day)' },
        { value: 'pm', label: 'Afternoon (PM half-day)' },
      ],
    } })});
  });
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
    await mockLeaveOptions(page);
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

    // Filing happens in a modal on this page — there is no redirect. The filed
    // request comes back through the invalidated list, where the Status cell
    // shows the label LeaveRequestStatus::label() produces for pending_dept.
    await expect(page.getByRole('cell', { name: 'Pending department head' })).toBeVisible({ timeout: 5000 });
    // Toast confirms submission
    await expect(page.getByText(/Leave request submitted for approval/i)).toBeVisible({ timeout: 5000 });
  });

  test('department head approves → pending_hr (cross-role)', async ({ page }) => {
    let status = 'pending_dept';
    await mockLeaveOptions(page);
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
    // The chip carries the label the options endpoint sends, capital P — not the
    // `status.replace('_',' ')` fallback a missing options mock would produce.
    await expect(page.getByText('Pending hr', { exact: true })).toBeVisible({ timeout: 5000 });

    // The positive half of the crumb rule (PageHeader drops Chip text when
    // lending the title to the trail): the trail must NAME the request and must
    // not carry its status. Without this pair, an implementation that dropped the
    // whole title as soon as it saw any chip would satisfy every other assertion
    // in this suite — the crumb would simply fall back to '…' and nothing would
    // notice.
    const trail = page.getByRole('navigation', { name: 'Breadcrumb' });
    await expect(trail).toContainText(LEAVE_NO);
    await expect(trail).not.toContainText(/pending/i);
  });

  test('HR officer approves → approved (cross-role, final state)', async ({ page }) => {
    let status = 'pending_hr';
    await mockLeaveOptions(page);
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
    // Scoped to the heading because the ChainHeader also has an 'Approved' step;
    // the record number in the same name proves the heading is this request's.
    await expect(page.getByRole('heading', { name: `${LEAVE_NO} Approved` })).toBeVisible({ timeout: 5000 });
  });

  test('SoD: depthead cannot approve their own leave (422 error)', async ({ page }) => {
    await mockLeaveOptions(page);
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

    // Unconditional. This was wrapped in `if (await button.isVisible())`, with a
    // note that the test "passes either way" — and `isVisible()` does not
    // auto-wait, so a slow detail fetch, a render throw or a drifted selector all
    // skipped the body and reported green. Deleting the SoD handling from the page
    // outright would not have failed it.
    //
    // The fixture makes the depthead the requester, so there is one right answer:
    // the SPA does NOT hide Approve from the requester (leaves/detail.tsx gates on
    // status and `leave.approve_dept`, nothing else), because SoD is enforced
    // server-side and the backend gate is the real one. The refusal therefore has
    // to arrive as a message. If the page ever does hide the button, this fails
    // here and someone decides deliberately — which is the point.
    await expect(detail.approveDeptButton).toBeVisible();
    await detail.approveDept();
    // The SoD guard returns 422 → the SPA shows the error.
    //
    // Assert the SERVER's sentence, not the page's 'Failed to approve.'
    // fallback. `reportMutationError` prefers a message the API deliberately
    // sent, because "You cannot act on a record you submitted" tells the
    // approver what rule stopped them and what to do instead; "Failed to
    // approve." tells them only that something went wrong, and would read the
    // same for a dropped connection. So the fallback never reaches the screen
    // here, and asserting it would only prove the specific message got lost.
    await expect(page.getByText(/cannot act on a record you submitted/i)).toBeVisible({ timeout: 5000 });
    // And the request is still awaiting the department head — a refused approval
    // must not leave the optimistic 'pending_hr' patch on screen.
    await expect(page.getByText('Pending dept', { exact: true })).toBeVisible();
  });
});
