import { expect, test, type Page } from '@playwright/test';
import {
  baseUrl,
  captureNetwork,
  loginAs,
  relevantErrors,
  statefulHeaders,
} from './helpers';
import { localIsoDate } from '../src/lib/formatDate';

interface LeaveRecord {
  id: string;
  leave_request_no: string;
  start_date: string;
  end_date: string;
  days: string;
  status: string;
  dept_approver: { id: string; name: string } | null;
  dept_approved_at: string | null;
  hr_approver: { id: string; name: string } | null;
  hr_approved_at: string | null;
  cancelled_by: { id: string; name: string } | null;
  cancelled_at: string | null;
};

interface Balance {
  leave_type?: { code: string } | null;
  total_credits: string;
  used: string;
  remaining: string;
}

interface ApprovalCard {
  id: string;
  type: string;
  role_slug: string;
  link: string;
}

interface ApprovalBoard {
  my_action: ApprovalCard[];
}

interface Notice {
  type: string;
  data: { entity_id?: string; link_to?: string };
}

interface AuditEntry {
  action: string;
  user?: { id: string; name: string } | null;
}

interface AttendanceEntry {
  date: string;
  status: string;
  remarks: string | null;
}

async function apiData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(new URL(path, baseUrl()).toString(), {
    headers: statefulHeaders(),
  });
  const body = await response.text();
  expect(response.status(), `${path} returned ${response.status()}: ${body}`).toBe(200);
  return (JSON.parse(body) as { data: T }).data;
}

async function leave(page: Page, id: string): Promise<LeaveRecord> {
  return apiData<LeaveRecord>(page, `/api/v1/leaves/requests/${id}`);
}

async function board(page: Page): Promise<ApprovalBoard> {
  return apiData<ApprovalBoard>(page, '/api/v1/approvals/board?type=leave');
}

async function notices(page: Page, type: string): Promise<Notice[]> {
  return apiData<Notice[]>(page, `/api/v1/notifications?type=${encodeURIComponent(type)}&per_page=100`);
}

async function auditTrail(page: Page, id: string): Promise<AuditEntry[]> {
  const query = new URLSearchParams({ model_type: 'LeaveRequest', model_id: id, per_page: '100' });
  return apiData<AuditEntry[]>(page, `/api/v1/admin/audit-logs/entity?${query}`);
}

async function balances(page: Page): Promise<Balance[]> {
  return apiData<Balance[]>(page, '/api/v1/leaves/balances/me');
}

async function attendance(page: Page, date: string): Promise<AttendanceEntry[]> {
  const query = new URLSearchParams({ from: date, to: date, per_page: '100' });
  return apiData<AttendanceEntry[]>(page, `/api/v1/hr/self-service/attendance?${query}`);
}

async function allAttendance(page: Page, date: string, employeeId: string): Promise<AttendanceEntry[]> {
  const query = new URLSearchParams({
    employee_id: employeeId,
    from: date,
    to: date,
    trashed: 'with',
    per_page: '100',
  });
  return apiData<AttendanceEntry[]>(page, `/api/v1/attendance/attendances?${query}`);
}

async function findFreeBusinessDate(page: Page, adminPage: Page, employeeId: string): Promise<string> {
  const base = new Date();
  base.setHours(0, 0, 0, 0);
  base.setDate(base.getDate() + 14);

  for (let offset = 0; offset < 75; offset += 1) {
    const candidate = new Date(base);
    candidate.setDate(candidate.getDate() + offset);
    if (candidate.getFullYear() !== base.getFullYear()) break;
    if (candidate.getDay() === 0) continue;
    const date = localIsoDate(candidate);
    const query = new URLSearchParams({ from: date, to: date, per_page: '100' });
    const [options, requests, rows] = await Promise.all([
      apiData<{ holiday_dates: string[] }>(page, `/api/v1/leaves/requests/options?${query}`),
      apiData<LeaveRecord[]>(page, `/api/v1/leaves/requests?${query}`),
      allAttendance(adminPage, date, employeeId),
    ]);
    const overlaps = requests.some(
      (request) =>
        !['rejected', 'cancelled'].includes(request.status) &&
        request.start_date <= date && request.end_date >= date,
    );
    if (!options.holiday_dates.includes(date) && !overlaps && rows.length === 0) return date;
  }

  throw new Error('No free business date found for the leave-chain test.');
}

async function approveFromInbox(page: Page, request: LeaveRecord): Promise<void> {
  await page.goto('/approvals');
  const card = page.getByRole('button').filter({ hasText: request.leave_request_no });
  await expect(card.first()).toBeVisible();
  await card.first().click();
  await expect(page).toHaveURL(new RegExp(`/hr/leaves/${request.id}$`));
  await page.getByRole('button', { name: 'Approve', exact: true }).click();

  const dialog = page.getByRole('dialog', { name: 'Approve leave request?' });
  await expect(dialog).toBeVisible();
  const stage = request.status === 'pending_dept' ? 'dept' : 'hr';
  const action = page.waitForResponse(
    (response) =>
      response.url().endsWith(`/api/v1/leaves/requests/${request.id}/approve-${stage}`) &&
      response.request().method() === 'PATCH',
  );
  await dialog.getByRole('button', { name: 'Approve', exact: true }).click();
  const response = await action;
  expect(response.status(), await response.text()).toBe(200);
}

test('employee follows own leave through both approvals and reverses attendance and balance on cancel', async ({ browser }) => {
  test.setTimeout(180_000);
  const baseURL = baseUrl();
  const contexts = await Promise.all(
    ['employee', 'department_head', 'hr_officer', 'system_admin'].map(() =>
      browser.newContext({ baseURL }),
    ),
  );
  const [employeePage, departmentPage, hrPage, adminPage] = await Promise.all(
    contexts.map((context) => context.newPage()),
  );
  const employeeNet = captureNetwork(employeePage);
  const departmentNet = captureNetwork(departmentPage);
  const hrNet = captureNetwork(hrPage);
  const adminNet = captureNetwork(adminPage);
  let requestId: string | undefined;
  let cancelled = false;
  let cleanupFailure: string | undefined;

  try {
    await Promise.all([
      loginAs(employeePage, 'employee@ogami.test', 'password'),
      loginAs(departmentPage, 'depthead@ogami.test', 'password'),
      loginAs(hrPage, 'hr@ogami.test', 'password'),
      loginAs(adminPage, 'admin@ogami.test', 'password'),
    ]);
    const [employeeActor, departmentActor, hrActor, initialBalances] = await Promise.all([
      apiData<{ id: string; employee?: { id: string } | null }>(employeePage, '/api/v1/auth/user'),
      apiData<{ id: string }>(departmentPage, '/api/v1/auth/user'),
      apiData<{ id: string }>(hrPage, '/api/v1/auth/user'),
      balances(employeePage),
    ]);
    const initialBalance = initialBalances.find((row) => row.leave_type?.code === 'VL');
    expect(initialBalance, 'Vacation Leave opening balance is seeded').toBeDefined();
    expect(employeeActor.employee?.id, 'employee has linked employee record').toBeTruthy();
    const date = await findFreeBusinessDate(employeePage, adminPage, employeeActor.employee!.id);

    await employeePage.goto('/self-service/leaves');
    await employeePage.getByRole('button', { name: 'New request' }).first().click();
    await employeePage.getByLabel('Leave type').selectOption({ label: 'Vacation Leave' });
    await employeePage.getByLabel('Start date').fill(date);
    await employeePage.getByLabel('End date').fill(date);
    await employeePage.getByLabel('Reason (optional)').fill('Real UI chain audit');
    await expect(employeePage.getByRole('button', { name: 'Submit request' })).toBeEnabled();

    const createdResponse = employeePage.waitForResponse(
      (response) =>
        response.url().endsWith('/api/v1/leaves/requests') &&
        response.request().method() === 'POST',
    );
    await employeePage.getByRole('button', { name: 'Submit request' }).click();
    const create = await createdResponse;
    const createBody = await create.json();
    expect(create.status(), JSON.stringify(createBody)).toBe(201);
    const created = createBody.data as LeaveRecord;
    requestId = created.id;
    expect(created.status).toBe('pending_dept');
    await expect(employeePage.getByText(created.leave_request_no)).toBeVisible();

    const submitted = await leave(employeePage, requestId);
    expect(submitted.status).toBe('pending_dept');
    expect(submitted.dept_approved_at).toBeNull();
    expect(submitted.hr_approved_at).toBeNull();
    expect((await balances(employeePage)).find((row) => row.leave_type?.code === 'VL')).toMatchObject({
      used: initialBalance!.used,
      remaining: initialBalance!.remaining,
    });
    expect(await attendance(employeePage, date)).toEqual([]);

    const departmentQueue = await board(departmentPage);
    expect(departmentQueue.my_action).toContainEqual(
      expect.objectContaining({
        id: requestId,
        type: 'leave',
        role_slug: 'department_head',
        link: `/hr/leaves/${requestId}`,
      }),
    );
    const submittedNotification = await notices(departmentPage, 'leave.submitted');
    expect(submittedNotification).toContainEqual(
      expect.objectContaining({
        data: expect.objectContaining({ entity_id: requestId, link_to: `/hr/leaves/${requestId}` }),
      }),
    );
    const createdAudit = await auditTrail(adminPage, requestId);
    expect(createdAudit.length).toBeGreaterThan(0);
    expect(createdAudit.some((entry) => entry.user?.id === employeeActor.id)).toBe(true);

    // The request number must open the owner's detail page; list-only leaves
    // strand employees after filing and after approval notifications.
    const detailLink = employeePage.getByRole('link', { name: `View leave request ${created.leave_request_no}` });
    await expect(detailLink).toBeVisible();
    await detailLink.click();
    await expect(employeePage).toHaveURL(new RegExp(`/self-service/leaves/${requestId}$`));
    await expect(employeePage.getByText('Approval chain')).toBeVisible();

    await approveFromInbox(departmentPage, submitted);
    const pendingHr = await leave(employeePage, requestId);
    expect(pendingHr.status).toBe('pending_hr');
    expect(pendingHr.dept_approver?.id).toBe(departmentActor.id);
    expect(pendingHr.dept_approved_at).not.toBeNull();
    expect(pendingHr.hr_approver).toBeNull();
    expect((await balances(employeePage)).find((row) => row.leave_type?.code === 'VL')).toMatchObject({
      used: initialBalance!.used,
      remaining: initialBalance!.remaining,
    });
    expect(await attendance(employeePage, date)).toEqual([]);
    expect((await board(departmentPage)).my_action.some((card) => card.id === requestId)).toBe(false);
    expect((await board(hrPage)).my_action).toContainEqual(
      expect.objectContaining({ id: requestId, type: 'leave', role_slug: 'hr_officer' }),
    );
    expect(await notices(hrPage, 'leave.pending_hr')).toContainEqual(
      expect.objectContaining({ data: expect.objectContaining({ entity_id: requestId }) }),
    );
    const deptAudit = await auditTrail(adminPage, requestId);
    expect(deptAudit.length).toBeGreaterThan(createdAudit.length);
    expect(deptAudit.some((entry) => entry.user?.id === departmentActor.id)).toBe(true);

    await approveFromInbox(hrPage, pendingHr);
    const approved = await leave(employeePage, requestId);
    expect(approved.status).toBe('approved');
    expect(approved.dept_approver?.id).toBe(departmentActor.id);
    expect(approved.hr_approver?.id).toBe(hrActor.id);
    expect(approved.dept_approved_at).not.toBeNull();
    expect(approved.hr_approved_at).not.toBeNull();
    const approvedBalance = (await balances(employeePage)).find((row) => row.leave_type?.code === 'VL');
    expect(Number(approvedBalance?.used)).toBe(Number(initialBalance!.used) + 1);
    expect(Number(approvedBalance?.remaining)).toBe(Number(initialBalance!.remaining) - 1);
    expect(await attendance(employeePage, date)).toContainEqual(
      expect.objectContaining({ date, status: 'on_leave', remarks: `leave:${approved.leave_request_no}` }),
    );
    const approvalNotice = (await notices(employeePage, 'leave.approved')).find(
      (notice) => notice.data.entity_id === requestId,
    );
    expect(approvalNotice?.data.link_to).toBe(`/self-service/leaves/${requestId}`);
    const hrAudit = await auditTrail(adminPage, requestId);
    expect(hrAudit.length).toBeGreaterThan(deptAudit.length);
    expect(hrAudit.some((entry) => entry.user?.id === hrActor.id)).toBe(true);

    await employeePage.goto(approvalNotice!.data.link_to!);
    await expect(employeePage).toHaveURL(new RegExp(`/self-service/leaves/${requestId}$`));
    await employeePage.getByRole('button', { name: 'Cancel', exact: true }).click();
    const cancelDialog = employeePage.getByRole('dialog', { name: 'Cancel leave request?' });
    await expect(cancelDialog).toBeVisible();
    const cancelAction = employeePage.waitForResponse(
      (response) =>
        response.url().endsWith(`/api/v1/leaves/requests/${requestId}/cancel`) &&
        response.request().method() === 'PATCH',
    );
    await cancelDialog.getByRole('button', { name: 'Yes, cancel', exact: true }).click();
    const cancelResponse = await cancelAction;
    expect(cancelResponse.status(), await cancelResponse.text()).toBe(200);
    cancelled = true;

    const cancelledRequest = await leave(employeePage, requestId);
    expect(cancelledRequest.status).toBe('cancelled');
    expect(cancelledRequest.cancelled_by?.id).toBe(employeeActor.id);
    expect(cancelledRequest.cancelled_at).not.toBeNull();
    expect((await balances(employeePage)).find((row) => row.leave_type?.code === 'VL')).toMatchObject({
      used: initialBalance!.used,
      remaining: initialBalance!.remaining,
    });
    expect(await attendance(employeePage, date)).toEqual([]);
    expect((await board(departmentPage)).my_action.some((card) => card.id === requestId)).toBe(false);
    expect((await board(hrPage)).my_action.some((card) => card.id === requestId)).toBe(false);
    const cancelledAudit = await auditTrail(adminPage, requestId);
    expect(cancelledAudit.length).toBeGreaterThan(hrAudit.length);
    expect(cancelledAudit.some((entry) => entry.user?.id === employeeActor.id)).toBe(true);

    for (const [name, net] of [
      ['employee', employeeNet],
      ['department head', departmentNet],
      ['HR officer', hrNet],
      ['system admin', adminNet],
    ] as const) {
      expect(relevantErrors(net), `${name} console errors`).toEqual([]);
      expect(net.failedRequests, `${name} failed API responses`).toEqual([]);
    }
  } finally {
    if (requestId && !cancelled) {
      try {
        await employeePage.request.get(new URL('/sanctum/csrf-cookie', baseURL).toString(), {
          headers: statefulHeaders(),
        });
        const xsrf = (await employeePage.context().cookies(baseURL)).find((cookie) => cookie.name === 'XSRF-TOKEN');
        if (!xsrf) {
          cleanupFailure = 'Leave-chain cleanup failed: CSRF cookie was not issued.';
        } else {
          const response = await employeePage.request.patch(
            new URL(`/api/v1/leaves/requests/${requestId}/cancel`, baseURL).toString(),
            { headers: { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) } },
          );
          if (!response.ok()) {
            cleanupFailure = `Leave-chain cleanup failed: ${response.status()} ${await response.text()}`;
          }
        }
      } catch (error) {
        cleanupFailure = `Leave-chain cleanup failed: ${String(error)}`;
      }
    }
    employeeNet.stop();
    departmentNet.stop();
    hrNet.stop();
    adminNet.stop();
    await Promise.allSettled(contexts.map((context) => context.close()));
  }
  if (cleanupFailure) throw new Error(cleanupFailure);
});
