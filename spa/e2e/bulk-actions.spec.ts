/**
 * Bulk actions on the approval queues — and specifically, whether they tell the
 * truth when a batch only partly works.
 *
 * The DataTable's selection and bulk-action bar are unit-testable. What is not
 * is the thing that actually goes wrong with a bulk action: ten rows selected,
 * three rejected by a business rule, and a green "Done!" toast. That failure
 * passes typecheck, passes lint, and passes every component test, because the
 * bug is in which toast was chosen — so it has to be asserted in a browser
 * against a real response.
 *
 * The three toast kinds are distinguishable from the DOM without reading colour,
 * which is why the assertions below are stable: `main.tsx` renders `aria-live`
 * as `assertive` for an error and `polite` otherwise, and gives a success toast
 * an icon classed `text-success-fg`. "Not a success toast" is therefore a real
 * assertion, not a guess about pixels.
 */
import { test, expect, type Page } from './fixtures';
import { loginAs, mockAuth, mockList } from './helpers-extended';

// ─── Fixtures ────────────────────────────────────────────────────

function leaveRow(over: Partial<Record<string, unknown>> = {}) {
  return {
    id: 'lr1',
    leave_request_no: 'LR-202604-0045',
    employee: { id: 'e1', employee_no: 'OGM-2026-0142', full_name: 'Ana Reyes', department: 'Production' },
    leave_type: { id: 'lt1', code: 'VL', name: 'Vacation Leave' },
    start_date: '2026-04-20',
    end_date: '2026-04-21',
    days: '2.00',
    half_day_period: null,
    reason: 'Family matter',
    document_path: null,
    status: 'pending_dept',
    status_label: 'Pending dept',
    dept_approver: null,
    dept_approved_at: null,
    hr_approver: null,
    hr_approved_at: null,
    rejection_reason: null,
    created_at: '2026-04-10T08:00:00Z',
    updated_at: '2026-04-10T08:00:00Z',
    ...over,
  };
}

function overtimeRow(over: Partial<Record<string, unknown>> = {}) {
  return {
    id: 'ot1',
    employee: { id: 'e1', employee_no: 'OGM-2026-0142', full_name: 'Ana Reyes' },
    date: '2026-04-20',
    hours_requested: '2.00',
    reason: 'Mold changeover overrun',
    status: 'pending',
    status_label: 'Pending',
    approver: null,
    approved_at: null,
    rejection_reason: null,
    is_auto_detected: false,
    created_at: '2026-04-20T18:00:00Z',
    updated_at: '2026-04-20T18:00:00Z',
    ...over,
  };
}

// ─── Toast helpers ───────────────────────────────────────────────

/** Any toast currently on screen. */
const toasts = (page: Page) => page.locator('[role="status"]');

/**
 * A success toast, identified by the icon `main.tsx` gives only to that type.
 * Asserting its ABSENCE is the point: an error and a success toast can carry
 * the same words, and only one of them is honest about a partial batch.
 */
const successToast = (page: Page) => page.locator('[role="status"]:has(.text-success-fg)');

/** Errors are the only toasts rendered assertive. */
const errorToast = (page: Page) => page.locator('[role="status"][aria-live="assertive"]');

/** Select every row on the page via the header checkbox. */
async function selectAllRows(page: Page): Promise<void> {
  await page.getByLabel('Select all rows').click();
}

// ═══════════════════════════════════════════════════════════════════
// Leave requests
// ═══════════════════════════════════════════════════════════════════

test.describe('leave requests — bulk approve', () => {
  test('selecting rows offers the action and reports a clean batch as done', async ({ page }) => {
    mockList(page, '**/api/v1/leaves/requests*', [
      leaveRow(),
      leaveRow({ id: 'lr2', leave_request_no: 'LR-202604-0046' }),
    ]);
    // Registered after the list mock so it wins the POST — Playwright matches
    // the most recently registered route first.
    let sentIds: unknown = null;
    await page.route('**/api/v1/leaves/requests/bulk-approve-dept', async (route) => {
      sentIds = route.request().postDataJSON()?.ids;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { approved: [leaveRow({ status: 'approved' }), leaveRow({ id: 'lr2', status: 'approved' })], failed: [] } }),
      });
    });

    await loginAs(page, 'hr', '/hr/leaves');
    await expect(page.getByText('LR-202604-0045')).toBeVisible();

    await selectAllRows(page);
    // The count in the bar is the count that will be submitted — selection is
    // page-scoped by design, so these must not drift apart.
    await expect(page.getByText('2 selected')).toBeVisible();

    await page.getByRole('button', { name: 'Approve selected' }).click();

    await expect(successToast(page)).toContainText('2 leave requests approved.');
    expect(sentIds).toEqual(['lr1', 'lr2']);
  });

  test('a partly failed batch is not reported as a success', async ({ page }) => {
    mockList(page, '**/api/v1/leaves/requests*', [
      leaveRow(),
      leaveRow({ id: 'lr2', leave_request_no: 'LR-202604-0046' }),
      leaveRow({ id: 'lr3', leave_request_no: 'LR-202604-0047' }),
    ]);
    await page.route('**/api/v1/leaves/requests/bulk-approve-dept', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            approved: [leaveRow({ status: 'approved' })],
            // The reason is the only thing that tells the approver what to do
            // next, and the old style of toast discarded it.
            failed: [
              { reason: 'Insufficient leave balance.' },
              { reason: 'Insufficient leave balance.' },
            ],
          },
        }),
      });
    });

    await loginAs(page, 'hr', '/hr/leaves');
    await expect(page.getByText('LR-202604-0047')).toBeVisible();

    await selectAllRows(page);
    await page.getByRole('button', { name: 'Approve selected' }).click();

    const failure = errorToast(page);
    // Counted against the selection, not against what succeeded: "Approved 1"
    // alone would be true and still misleading.
    await expect(failure).toContainText('Approved 1 of 3');
    await expect(failure).toContainText('2 failed');
    await expect(failure).toContainText('Insufficient leave balance.');
    // The load-bearing assertion of this file.
    await expect(successToast(page)).toHaveCount(0);
  });

  test('a user who can reach the page but not approve gets no checkboxes', async ({ page }) => {
    // `/hr/leaves` admits three permissions, and only two of them are approval
    // rights: `leave.types.manage` opens the page to manage leave types. That
    // user must not be offered a batch the backend would refuse, so the gate on
    // selection has to be the approval permissions rather than page access.
    // No seeded role holds types.manage alone, hence the bespoke user.
    mockList(page, '**/api/v1/leaves/requests*', [leaveRow()]);
    await mockAuth(page, {
      id: 'lt0001', name: 'Leave Type Admin', email: 'types@ogami.test',
      roleSlug: 'hr_officer', roleName: 'HR Officer',
      permissions: ['leave.view', 'leave.types.manage'],
      employee: { id: 'emp_lt', employee_no: 'OGM-2026-0900' },
    });
    await page.goto('/hr/leaves', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('LR-202604-0045')).toBeVisible();

    // A bulk action a user cannot perform must never be on screen to be
    // clicked — the frontend guard is UX, but offering a guaranteed 403 is not.
    await expect(page.getByLabel('Select all rows')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Approve selected' })).toHaveCount(0);
  });

  test('an approver is never offered a batch that would 403', async ({ page }) => {
    // Only the HR stage is pending here, and `hr` holds both permissions, so
    // the dept endpoint must not be called at all.
    mockList(page, '**/api/v1/leaves/requests*', [leaveRow({ status: 'pending_hr', status_label: 'Pending HR' })]);
    let deptCalled = false;
    let hrCalled = false;
    await page.route('**/api/v1/leaves/requests/bulk-approve-dept', async (route) => {
      deptCalled = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { approved: [], failed: [] } }) });
    });
    await page.route('**/api/v1/leaves/requests/bulk-approve-hr', async (route) => {
      hrCalled = true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { approved: [leaveRow({ status: 'approved' })], failed: [] } }),
      });
    });

    await loginAs(page, 'hr', '/hr/leaves');
    await expect(page.getByText('LR-202604-0045')).toBeVisible();
    await selectAllRows(page);
    await page.getByRole('button', { name: 'Approve selected' }).click();

    await expect(successToast(page)).toContainText('1 leave request approved.');
    expect(hrCalled).toBe(true);
    expect(deptCalled).toBe(false);
  });
});

// ═══════════════════════════════════════════════════════════════════
// Overtime requests
// ═══════════════════════════════════════════════════════════════════

test.describe('overtime requests — bulk approve', () => {
  test('a partly failed batch is not reported as a success', async ({ page }) => {
    mockList(page, '**/api/v1/attendance/overtime-requests*', [
      overtimeRow(),
      overtimeRow({ id: 'ot2' }),
    ]);
    await page.route('**/api/v1/attendance/overtime-requests/bulk-approve', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          message: '1 approved, 1 failed.',
          approved_count: 1,
          failed: [{ id: 2, reason: 'Overtime exceeds the 4-hour maximum.' }],
          data: [],
        }),
      });
    });

    await loginAs(page, 'hr', '/hr/attendance/overtime');
    await expect(page.getByText('Mold changeover overrun').first()).toBeVisible();

    await selectAllRows(page);
    await expect(page.getByText('2 selected')).toBeVisible();
    await page.getByRole('button', { name: 'Approve selected' }).click();

    const failure = errorToast(page);
    await expect(failure).toContainText('Approved 1 of 2');
    await expect(failure).toContainText('Overtime exceeds the 4-hour maximum.');
    await expect(successToast(page)).toHaveCount(0);
    // And the raw integer primary key the API sends alongside the reason stays
    // out of the UI — hash ids are the only ids a user ever sees.
    await expect(toasts(page).first()).not.toContainText('id');
  });

  test('rows that were never pending are reported as left unchanged', async ({ page }) => {
    mockList(page, '**/api/v1/attendance/overtime-requests*', [
      overtimeRow(),
      overtimeRow({ id: 'ot2', status: 'approved', status_label: 'Approved' }),
    ]);
    let sentIds: string[] = [];
    await page.route('**/api/v1/attendance/overtime-requests/bulk-approve', async (route) => {
      sentIds = route.request().postDataJSON()?.ids ?? [];
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ message: '1 approved, 0 failed.', approved_count: 1, failed: [], data: [] }),
      });
    });

    await loginAs(page, 'hr', '/hr/attendance/overtime');
    await expect(page.getByText('Mold changeover overrun').first()).toBeVisible();

    await selectAllRows(page);
    await page.getByRole('button', { name: 'Approve selected' }).click();

    // Zero failures is not the same as everything approved: the approved row
    // was never submitted. Saying "Approved 1" in green would overstate what
    // happened to a selection of two.
    await expect(toasts(page).first()).toContainText('Approved 1 of 2');
    await expect(toasts(page).first()).toContainText('left unchanged');
    await expect(successToast(page)).toHaveCount(0);
    expect(sentIds).toEqual(['ot1']);
  });

  test('the batch is gated on the same permission the row button uses', async ({ page }) => {
    // `/hr/attendance/overtime` already requires `attendance.ot.approve`, so on
    // this page the gate can only ever be open — which is exactly why it is
    // worth pinning: the row's Approve button and the bulk bar read the same
    // permission, so they cannot drift apart if the route gate is ever widened
    // the way `/hr/leaves` was.
    mockList(page, '**/api/v1/attendance/overtime-requests*', [overtimeRow()]);
    await loginAs(page, 'hr', '/hr/attendance/overtime');
    await expect(page.getByText('Mold changeover overrun').first()).toBeVisible();

    await expect(page.getByLabel('Select all rows')).toHaveCount(1);
    await expect(page.getByRole('button', { name: 'Approve' }).first()).toBeVisible();
  });
});

// ═══════════════════════════════════════════════════════════════════
// Items — the archive arm, and the only client-side fan-out
// ═══════════════════════════════════════════════════════════════════

function itemRow(over: Partial<Record<string, unknown>> = {}) {
  return {
    id: 'it1',
    code: 'RES-PP-001',
    name: 'Polypropylene resin',
    description: null,
    category: { id: 'c1', name: 'Resin' },
    item_type: 'raw_material',
    item_type_label: 'Raw material',
    unit_of_measure: 'kg',
    standard_cost: '82.5000',
    reorder_method: 'reorder_point',
    reorder_point: '500.000',
    safety_stock: '200.000',
    minimum_order_quantity: '100.000',
    lead_time_days: 14,
    is_critical: false,
    is_active: true,
    quality_plan_ready: true,
    on_hand_quantity: '0.000',
    reserved_quantity: '0.000',
    available_quantity: '0.000',
    stock_status: 'ok',
    created_at: '2026-01-05T00:00:00Z',
    updated_at: '2026-01-05T00:00:00Z',
    ...over,
  };
}

test.describe('items — bulk archive', () => {
  test('a fan-out that partly fails reports the count and still offers an undo', async ({ page }) => {
    mockList(page, '**/api/v1/inventory/items*', [
      itemRow(),
      itemRow({ id: 'it2', code: 'RES-PP-002' }),
      itemRow({ id: 'it3', code: 'RES-PP-003', on_hand_quantity: '1200.000' }),
    ]);
    // One row refuses for a real business reason. The batch must not abort on
    // it, and the reason has to reach the screen — it is the only thing that
    // tells the user why that item stayed.
    const archived: string[] = [];
    await page.route('**/api/v1/inventory/items/*', async (route) => {
      if (route.request().method() !== 'DELETE') return route.fallback();
      const id = route.request().url().split('/').pop() ?? '';
      if (id === 'it3') {
        await route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'Cannot archive an item with stock on hand.' }),
        });
        return;
      }
      archived.push(id);
      await route.fulfill({ status: 204, body: '' });
    });

    await loginAs(page, 'admin', '/inventory/items');
    await expect(page.getByText('RES-PP-003')).toBeVisible();

    await selectAllRows(page);
    await page.getByRole('button', { name: 'Archive selected' }).click();
    // The batch keeps its confirmation: fifty records is not a click to take
    // back by reflex, even with an undo waiting behind it.
    await page.getByRole('button', { name: 'Archive', exact: true }).click();

    const failure = errorToast(page);
    await expect(failure).toContainText('Archived 2 of 3');
    await expect(failure).toContainText('Cannot archive an item with stock on hand.');
    await expect(successToast(page)).toHaveCount(0);
    expect(archived.sort()).toEqual(['it1', 'it2']);

    // …and the two that did archive are still reversible. A partial batch is
    // exactly when the undo matters and exactly when it is easiest to forget.
    await expect(page.getByRole('button', { name: 'Undo' })).toBeVisible();
  });

  test('the All scope offers no batch, because it cannot tell archived from live', async ({ page }) => {
    mockList(page, '**/api/v1/inventory/items*', [itemRow()]);
    await loginAs(page, 'admin', '/inventory/items');
    await expect(page.getByText('RES-PP-001')).toBeVisible();
    await expect(page.getByLabel('Select all rows')).toHaveCount(1);

    // `Item` carries no `deleted_at`, so under "All" a batch would be guessing
    // which endpoint each row needs. Selection disappears rather than inviting
    // a click that is half wrong.
    await page.getByRole('radio', { name: 'Show active and archived records' }).click();
    await expect(page.getByLabel('Select all rows')).toHaveCount(0);
  });
});

