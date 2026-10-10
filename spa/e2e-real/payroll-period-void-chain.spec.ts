import { expect, test, type Page } from '@playwright/test';
import {
  baseUrl,
  captureNetwork,
  loginAs,
  relevantErrors,
  statefulHeaders,
} from './helpers';

type LifecycleStep = { key: string; label: string; state: 'pending' | 'active' | 'done' };
type PeriodState = {
  id: string;
  status: string;
  lifecycle_steps: LifecycleStep[];
  creator?: { id: string; name: string };
  computer?: { id: string; name: string };
  approver?: { id: string; name: string };
  finalizer?: { name: string };
  voided_at?: string | null;
  void_reason?: string | null;
  summary?: {
    employee_count: number;
    failed_count: number;
    total_gross: string;
    total_deductions: string;
    total_net: string;
    deduction_breakdown: {
      sss_employee_share: string;
      philhealth_employee_share: string;
      pagibig_employee_share: string;
      withholding_tax: string;
      loan_deductions: string;
    };
    uncategorized_deductions: string;
    net_adjustments: string;
  };
  bank_files?: Array<{ record_count: number; total_amount: string }>;
  gl_handoff_status?: string;
  gl_entry_number?: string | null;
  gl_reversal?: {
    original_entry_id: string;
    original_entry_number: string;
    reversal_entry_id: string;
    reversal_entry_number: string;
  } | null;
};
type PayrollRow = {
  gross_pay: string;
  sss_ee: string;
  philhealth_ee: string;
  pagibig_ee: string;
  withholding_tax: string;
  loan_deductions: string;
  other_deductions: string;
  adjustment_amount: string;
  total_deductions: string;
  net_pay: string;
  error_message: string | null;
};
type JournalEntry = {
  id: string;
  entry_number: string;
  reference_type: string | null;
  reference_id: string | null;
  status: string;
  reversed_by_entry_id: string | null;
  total_debit: string;
  total_credit: string;
  lines: Array<{ debit: string; credit: string; account: { id: string } | null }>;
};
type JournalEntrySummary = Omit<JournalEntry, 'lines'>;
type PayrollAnomalyFlag = {
  id: string;
  flag_type: string;
  is_resolved: boolean;
  resolution_remarks: string | null;
  resolved_by: { id: string; name: string } | null;
};
type AuditRow = {
  action: string;
  actor_type?: string;
  old_values?: Record<string, unknown>;
  new_values?: Record<string, unknown>;
  reason?: string | null;
  user?: { email: string } | null;
};

const cents = (value: string) => {
  const [whole, fraction = ''] = value.split('.');
  return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0').slice(0, 2));
};

async function readData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}${path}`, { headers: statefulHeaders() });
  expect(response.status(), `${path} must return its real API resource`).toBe(200);
  return ((await response.json()) as { data: T }).data;
}

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
  const xsrf = (await page.context().cookies(baseUrl())).find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(xsrf, 'the browser must hold the Sanctum CSRF cookie').toBeDefined();
  return { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? '') };
}

async function readPeriod(page: Page, id: string): Promise<PeriodState> {
  return readData<PeriodState>(page, `/api/v1/payroll-periods/${id}`);
}

async function auditTrail(page: Page, id: string, modelType = 'PayrollPeriod'): Promise<AuditRow[]> {
  return readData<AuditRow[]>(
    page,
    `/api/v1/admin/audit-logs/entity?model_type=${encodeURIComponent(modelType)}&model_id=${encodeURIComponent(id)}&per_page=100`,
  );
}

async function moneyTotals(page: Page, id: string, period: PeriodState): Promise<void> {
  const rows = await readData<PayrollRow[]>(page, `/api/v1/payroll-periods/${id}/payrolls?per_page=100`);
  expect(rows.length).toBe(period.summary?.employee_count);
  expect(rows.every((row) => row.error_message === null)).toBe(true);

  const sum = (field: 'gross_pay' | 'total_deductions' | 'net_pay') =>
    rows.reduce((total, row) => total + cents(row[field]), 0n);

  expect(sum('gross_pay')).toBe(cents(period.summary?.total_gross ?? '0.00'));
  expect(sum('total_deductions')).toBe(cents(period.summary?.total_deductions ?? '0.00'));
  expect(sum('net_pay')).toBe(cents(period.summary?.total_net ?? '0.00'));
  const deductions = period.summary?.deduction_breakdown;
  expect(rows.reduce((total, row) => total + cents(row.sss_ee), 0n)).toBe(cents(deductions?.sss_employee_share ?? '0.00'));
  expect(rows.reduce((total, row) => total + cents(row.philhealth_ee), 0n)).toBe(cents(deductions?.philhealth_employee_share ?? '0.00'));
  expect(rows.reduce((total, row) => total + cents(row.pagibig_ee), 0n)).toBe(cents(deductions?.pagibig_employee_share ?? '0.00'));
  expect(rows.reduce((total, row) => total + cents(row.withholding_tax), 0n)).toBe(cents(deductions?.withholding_tax ?? '0.00'));
  expect(rows.reduce((total, row) => total + cents(row.loan_deductions), 0n)).toBe(cents(deductions?.loan_deductions ?? '0.00'));
  expect(rows.reduce((total, row) => total + cents(row.other_deductions), 0n)).toBe(
    cents(period.summary?.uncategorized_deductions ?? '0.00'),
  );
  expect(rows.reduce((total, row) => total + cents(row.adjustment_amount), 0n)).toBe(cents(period.summary?.net_adjustments ?? '0.00'));
  expect(
    Object.values(deductions ?? {}).reduce((total, amount) => total + cents(amount), 0n),
  ).toBe(cents(period.summary?.total_deductions ?? '0.00'));
}

test('payroll period void chain leaves a truthful terminal timeline and audit trail', async ({ browser }) => {
  const actors = await Promise.all(Array.from({ length: 5 }, () => browser.newContext()));
  const [hrPage, approverPage, finalizerPage, adminPage, reviewerPage] = await Promise.all(
    actors.map((context) => context.newPage()),
  );
  const pages = [hrPage, approverPage, finalizerPage, adminPage, reviewerPage];
  const networks = pages.map((page) => captureNetwork(page));
  let periodId: string | undefined;

  try {
    await loginAs(hrPage, 'hr@ogami.test', 'password');
    await loginAs(approverPage, 'finance@ogami.test', 'password');
    await loginAs(finalizerPage, 'finance2@ogami.test', 'password');
    await loginAs(adminPage, 'admin@ogami.test', 'password');
    await loginAs(reviewerPage, 'payroll-reviewer@ogami.test', 'password');

    const existingPeriods = await readData<Array<{ period_start: string }>>(
      hrPage,
      '/api/v1/payroll-periods?per_page=100',
    );
    const usedStarts = new Set(existingPeriods.map((existing) => existing.period_start));
    const payrollMonth = new Date();
    payrollMonth.setUTCDate(1);
    payrollMonth.setUTCMonth(payrollMonth.getUTCMonth() - 1);
    while (usedStarts.has(`${payrollMonth.getUTCFullYear()}-${String(payrollMonth.getUTCMonth() + 1).padStart(2, '0')}-16`)) {
      payrollMonth.setUTCMonth(payrollMonth.getUTCMonth() - 1);
    }
    const year = payrollMonth.getUTCFullYear();
    const month = String(payrollMonth.getUTCMonth() + 1).padStart(2, '0');
    const lastDay = new Date(Date.UTC(year, payrollMonth.getUTCMonth() + 1, 0)).getUTCDate();
    const start = `${year}-${month}-16`;
    const end = `${year}-${month}-${String(lastDay).padStart(2, '0')}`;

    await hrPage.goto(`${baseUrl()}/payroll/periods/create`);
    await hrPage.getByLabel('Period start').fill(start);
    await hrPage.getByLabel('Period end').fill(end);
    await hrPage.getByLabel('Payroll date').fill(end);
    await hrPage.getByLabel('Finance & Accounting (FIN)').check();
    await expect(hrPage.getByText('Filtered', { exact: true })).toBeVisible();

    const createResponse = hrPage.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === '/api/v1/payroll-periods' &&
        response.request().method() === 'POST',
    );
    await hrPage.getByRole('button', { name: 'Create period', exact: true }).click();
    expect((await createResponse).status()).toBe(201);
    await expect(hrPage).toHaveURL(/\/payroll\/periods\/[A-Za-z0-9]+$/);
    periodId = new URL(hrPage.url()).pathname.split('/').pop();
    expect(periodId).toBeTruthy();

    let period = await readPeriod(hrPage, periodId!);
    expect(period.status).toBe('draft');
    expect(period.summary?.employee_count).toBe(0);
    expect(period.creator?.name).toBe('Maria Santos');
    const created = (await auditTrail(adminPage, periodId!)).find((row) => row.action === 'created');
    expect(created?.user?.email).toBe('hr@ogami.test');

    expect(await hrPage.getByRole('button', { name: 'Approve', exact: true }).count()).toBe(0);
    const computeResponse = hrPage.waitForResponse(
      (response) => response.url().endsWith(`/api/v1/payroll-periods/${periodId}/compute`),
    );
    await hrPage.getByRole('button', { name: 'Compute', exact: true }).click();
    expect((await computeResponse).status()).toBe(202);
    await expect
      .poll(async () => (await readPeriod(hrPage, periodId!)).status, { timeout: 60_000 })
      .toBe('computed');
    period = await readPeriod(hrPage, periodId!);
    expect(period.computer?.name).toBe('Maria Santos');
    expect(period.summary?.employee_count).toBeGreaterThan(0);
    expect(period.summary?.failed_count).toBe(0);
    await moneyTotals(hrPage, periodId!, period);
    await hrPage.getByRole('tab', { name: 'Deduction summary' }).click();
    await expect(hrPage.getByText('SSS employee share', { exact: true })).toBeVisible();
    await expect(hrPage.getByText('Loans and cash advances', { exact: true })).toBeVisible();
    await expect(hrPage.getByText(/Future enhancement|available per-employee/, { exact: false })).toHaveCount(0);
    await hrPage.getByRole('tab', { name: 'Employees' }).click();
    const computeAudits = await auditTrail(adminPage, periodId!);
    const computeStartAudit = computeAudits.find(
      (row) => row.action === 'updated' && row.new_values?.status === 'processing',
    );
    expect(computeStartAudit?.user?.email).toBe('hr@ogami.test');
    const computeAudit = computeAudits.find(
      (row) => row.action === 'updated' && row.new_values?.status === 'computed',
    );
    expect(computeAudit, 'the completed compute transition must be in the audit trail').toBeDefined();
    expect(computeAudit?.actor_type).toBe('system');

    await approverPage.goto(`${baseUrl()}/payroll/periods/${periodId}`);
    await expect(approverPage.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
    const approveResponse = approverPage.waitForResponse(
      (response) => response.url().endsWith(`/api/v1/payroll-periods/${periodId}/approve`),
    );
    await approverPage.getByRole('button', { name: 'Approve', exact: true }).click();
    await approverPage.getByRole('dialog').getByRole('button', { name: 'Approve', exact: true }).click();
    expect((await approveResponse).status()).toBe(200);
    period = await readPeriod(approverPage, periodId!);
    expect(period.status).toBe('approved');
    expect(period.approver?.name).toBe('Ana Reyes');
    await moneyTotals(approverPage, periodId!, period);
    expect(
      (await auditTrail(adminPage, periodId!)).some(
        (row) => row.action === 'payroll.period.approve' && row.user?.email === 'finance@ogami.test',
      ),
    ).toBe(true);

    await approverPage.reload();
    await expect(approverPage.getByRole('button', { name: 'Finalize', exact: true })).toHaveCount(0);
    await expect(approverPage.getByText('A different finance user must finalize this payroll.', { exact: true })).toBeVisible();
    const sameActorFinalize = await approverPage.request.patch(
      `${baseUrl()}/api/v1/payroll-periods/${periodId}/finalize`,
      { headers: await csrfHeaders(approverPage), data: {} },
    );
    expect(sameActorFinalize.status()).toBe(403);
    expect((await sameActorFinalize.json()).message).toBe(
      'The approver cannot finalize the same payroll period. A different finance user must finalize it.',
    );
    period = await readPeriod(approverPage, periodId!);
    expect(period.status).toBe('approved');
    expect(period.finalizer ?? null).toBeNull();
    expect(period.bank_files).toHaveLength(0);
    expect((await auditTrail(adminPage, periodId!)).some((row) => row.action === 'payroll.period.finalize')).toBe(false);

    await finalizerPage.goto(`${baseUrl()}/payroll/periods/${periodId}`);
    await expect(finalizerPage.getByRole('button', { name: 'Finalize', exact: true })).toBeVisible();
    const blockedFinalize = await finalizerPage.request.patch(
      `${baseUrl()}/api/v1/payroll-periods/${periodId}/finalize`,
      { headers: await csrfHeaders(finalizerPage), data: {} },
    );
    expect(blockedFinalize.status()).toBe(422);
    const blockedFinalizeBody = (await blockedFinalize.json()) as { message: string };
    expect(blockedFinalizeBody.message).toMatch(/^Cannot finalize: \d+ unresolved payroll anomaly flag\(s\)\./);
    period = await readPeriod(finalizerPage, periodId!);
    expect(period.status).toBe('approved');
    expect(period.finalizer ?? null).toBeNull();
    expect(period.bank_files).toHaveLength(0);
    expect((await auditTrail(adminPage, periodId!)).some((row) => row.action === 'payroll.period.finalize')).toBe(false);

    const finalizeResponse = finalizerPage.waitForResponse(
      (response) => response.url().endsWith(`/api/v1/payroll-periods/${periodId}/finalize`),
    );
    await finalizerPage.getByRole('button', { name: 'Finalize', exact: true }).click();
    await finalizerPage.getByRole('dialog').getByRole('button', { name: 'Finalize', exact: true }).click();
    const blockedUiResponse = await finalizeResponse;
    expect(blockedUiResponse.status()).toBe(422);
    expect((await blockedUiResponse.json()).message).toBe(blockedFinalizeBody.message);
    await expect(finalizerPage.getByText(blockedFinalizeBody.message, { exact: true })).toBeVisible();
    expect(networks[2].failedRequests).toContainEqual({
      url: expect.stringContaining(`/api/v1/payroll-periods/${periodId}/finalize`),
      method: 'PATCH',
      status: 422,
    });
    period = await readPeriod(finalizerPage, periodId!);
    expect(period.status).toBe('approved');
    expect(period.finalizer ?? null).toBeNull();

    await hrPage.goto(`${baseUrl()}/payroll/periods/${periodId}`);
    await hrPage.getByRole('tab', { name: 'Anomaly review' }).click();
    await expect(hrPage.getByText('Unresolved anomalies must be reviewed before payroll can be finalised.')).toBeVisible();
    const anomalyRows = await readData<PayrollAnomalyFlag[]>(
      hrPage,
      `/api/v1/payroll-periods/${periodId}/anomalies?is_resolved=false&per_page=100`,
    );
    expect(anomalyRows.length).toBeGreaterThan(0);
    await expect(hrPage.getByText(
      'You created or computed this payroll. A different authorized reviewer must resolve its anomaly flags.',
      { exact: true },
    )).toBeVisible();
    expect(await hrPage.getByRole('button', { name: 'Mark as reviewed' }).count()).toBe(0);
    const selfReview = await hrPage.request.patch(
      `${baseUrl()}/api/v1/payroll-anomalies/${anomalyRows[0].id}/resolve`,
      { headers: await csrfHeaders(hrPage), data: { remarks: 'Self-review attempt.' } },
    );
    expect(selfReview.status()).toBe(403);
    expect((await readData<PayrollAnomalyFlag[]>(
      hrPage,
      `/api/v1/payroll-periods/${periodId}/anomalies?is_resolved=false&per_page=100`,
    ))).toHaveLength(anomalyRows.length);
    expect((await auditTrail(adminPage, anomalyRows[0].id, 'PayrollAnomalyFlag')).some(
      (row) => row.action === 'payroll.anomaly.resolve',
    )).toBe(false);

    await reviewerPage.goto(`${baseUrl()}/payroll/periods/${periodId}`);
    await reviewerPage.getByRole('tab', { name: 'Anomaly review' }).click();
    await expect(reviewerPage.getByRole('button', { name: 'Mark as reviewed' }).first()).toBeVisible();

    for (const anomaly of anomalyRows) {
      await reviewerPage.getByRole('button', { name: 'Mark as reviewed' }).first().click();
      const dialog = reviewerPage.getByRole('dialog');
      const remarks = `Reviewed ${anomaly.flag_type} during payroll audit.`;
      await dialog.getByRole('textbox', { name: 'Remarks' }).fill(remarks);
      const resolveResponse = reviewerPage.waitForResponse(
        (response) => response.url().endsWith(`/api/v1/payroll-anomalies/${anomaly.id}/resolve`),
      );
      await dialog.getByRole('button', { name: 'Mark reviewed', exact: true }).click();
      expect((await resolveResponse).status()).toBe(200);
      await expect(reviewerPage.getByText('Anomaly marked as reviewed').last()).toBeVisible();

      const resolvedFlags = await readData<PayrollAnomalyFlag[]>(
        reviewerPage,
        `/api/v1/payroll-periods/${periodId}/anomalies?is_resolved=true&per_page=100`,
      );
      const resolved = resolvedFlags.find((flag) => flag.id === anomaly.id);
      expect(resolved?.is_resolved).toBe(true);
      expect(resolved?.resolution_remarks).toBe(remarks);
      expect(resolved?.resolved_by?.name).toBe('Audit Payroll Reviewer');
      const resolveAudits = await auditTrail(adminPage, anomaly.id, 'PayrollAnomalyFlag');
      expect(resolveAudits.some((row) =>
        row.action === 'payroll.anomaly.resolve'
        && row.user?.email === 'payroll-reviewer@ogami.test'
        && row.old_values?.is_resolved === false
        && row.new_values?.is_resolved === true
        && row.new_values?.resolution_remarks === remarks,
      )).toBe(true);

      const repeatResolve = await reviewerPage.request.patch(
        `${baseUrl()}/api/v1/payroll-anomalies/${anomaly.id}/resolve`,
        { headers: await csrfHeaders(reviewerPage), data: { remarks: 'Replace original review.' } },
      );
      expect(repeatResolve.status()).toBe(422);
      const unchanged = (await readData<PayrollAnomalyFlag[]>(
        reviewerPage,
        `/api/v1/payroll-periods/${periodId}/anomalies?is_resolved=true&per_page=100`,
      )).find((flag) => flag.id === anomaly.id);
      expect(unchanged?.resolution_remarks).toBe(remarks);
      expect((await auditTrail(adminPage, anomaly.id, 'PayrollAnomalyFlag')).filter(
        (row) => row.action === 'payroll.anomaly.resolve',
      )).toHaveLength(1);
    }

    expect(await readData<PayrollAnomalyFlag[]>(
      reviewerPage,
      `/api/v1/payroll-periods/${periodId}/anomalies?is_resolved=false&per_page=100`,
    )).toHaveLength(0);

    await finalizerPage.reload();
    await expect(finalizerPage.getByRole('button', { name: 'Finalize', exact: true })).toBeVisible();
    const successfulFinalize = finalizerPage.waitForResponse(
      (response) => response.url().endsWith(`/api/v1/payroll-periods/${periodId}/finalize`),
    );
    await finalizerPage.getByRole('button', { name: 'Finalize', exact: true }).click();
    await finalizerPage.getByRole('dialog').getByRole('button', { name: 'Finalize', exact: true }).click();
    expect((await successfulFinalize).status()).toBe(200);
    period = await readPeriod(finalizerPage, periodId!);
    expect(period.status).toBe('finalized');
    expect(period.finalizer?.name).toBe('Liza Gomez');
    expect(['posted', 'not_required']).toContain(period.gl_handoff_status);
    expect(period.gl_entry_number ?? null).not.toBeNull();
    await moneyTotals(finalizerPage, periodId!, period);
    const payableRows = (await readData<PayrollRow[]>(
      finalizerPage,
      `/api/v1/payroll-periods/${periodId}/payrolls?per_page=100`,
    )).filter((row) => row.error_message === null && cents(row.net_pay) > 0n);
    expect(period.bank_files).toHaveLength(1);
    expect(period.bank_files?.[0].record_count).toBe(payableRows.length);
    expect(cents(period.bank_files?.[0].total_amount ?? '0.00')).toBe(
      payableRows.reduce((total, row) => total + cents(row.net_pay), 0n),
    );
    const postedEntries = await readData<JournalEntrySummary[]>(
      finalizerPage,
      '/api/v1/journal-entries?reference_type=payroll_period&per_page=100',
    );
    const originalJournalSummary = postedEntries.find((entry) => entry.reference_id === periodId);
    expect(originalJournalSummary?.status).toBe('posted');
    const originalJournal = await readData<JournalEntry>(
      finalizerPage,
      `/api/v1/journal-entries/${originalJournalSummary?.id}`,
    );
    expect(originalJournal.total_debit).toBe(originalJournal.total_credit);
    expect(
      originalJournal.lines.reduce((sum, line) => sum + cents(line.debit), 0n),
    ).toBe(cents(originalJournal.total_debit));
    expect(
      originalJournal.lines.reduce((sum, line) => sum + cents(line.credit), 0n),
    ).toBe(cents(originalJournal.total_credit));
    expect(
      (await auditTrail(adminPage, periodId!)).some(
        (row) => row.action === 'payroll.period.finalize' && row.user?.email === 'finance2@ogami.test',
      ),
    ).toBe(true);
    expect(await finalizerPage.getByRole('button', { name: 'Void', exact: true }).count()).toBe(0);

    await adminPage.goto(`${baseUrl()}/payroll/periods/${periodId}`);
    await expect(adminPage.getByRole('button', { name: 'Void', exact: true })).toBeVisible();
    await adminPage.getByRole('button', { name: 'Void', exact: true }).click();
    await adminPage.getByRole('dialog').getByRole('textbox', { name: 'Reason' }).fill('Audit test correction');
    const voidResponse = adminPage.waitForResponse(
      (response) => response.url().endsWith(`/api/v1/payroll-periods/${periodId}/void`),
    );
    await adminPage.getByRole('dialog').getByRole('button', { name: 'Void period', exact: true }).click();
    expect((await voidResponse).status()).toBe(200);
    period = await readPeriod(adminPage, periodId!);
    expect(period.status).toBe('voided');
    expect(period.voided_at).toBeTruthy();
    expect(period.void_reason).toBe('Audit test correction');
    await moneyTotals(adminPage, periodId!, period);
    const reversedEntries = await readData<JournalEntrySummary[]>(
      adminPage,
      '/api/v1/journal-entries?reference_type=payroll_period&per_page=100',
    );
    const reversedOriginalSummary = reversedEntries.find((entry) => entry.id === originalJournal.id);
    expect(reversedOriginalSummary?.status).toBe('reversed');
    expect(reversedOriginalSummary?.reversed_by_entry_id).toBeTruthy();
    const reversedOriginal = await readData<JournalEntry>(
      adminPage,
      `/api/v1/journal-entries/${reversedOriginalSummary?.id}`,
    );
    const reversal = await readData<JournalEntry>(
      adminPage,
      `/api/v1/journal-entries/${reversedOriginal.reversed_by_entry_id}`,
    );
    expect(reversal.status).toBe('posted');
    expect(reversal.reference_type).toBe('journal_entry_reversal');
    expect(reversal.reference_id).toBe(reversedOriginal.id);
    expect(cents(reversal.total_debit)).toBe(cents(reversedOriginal.total_credit));
    expect(cents(reversal.total_credit)).toBe(cents(reversedOriginal.total_debit));
    expect(reversal.lines.map((line) => [line.account?.id, line.debit, line.credit])).toEqual(
      reversedOriginal.lines.map((line) => [line.account?.id, line.credit, line.debit]),
    );
    expect(period.gl_reversal).toEqual({
      original_entry_id: reversedOriginal.id,
      original_entry_number: reversedOriginal.entry_number,
      reversal_entry_id: reversal.id,
      reversal_entry_number: reversal.entry_number,
    });
    await expect(adminPage.getByRole('link', { name: reversal.entry_number, exact: true })).toBeVisible();
    expect(
      (await auditTrail(adminPage, periodId!)).some(
        (row) => row.action === 'payroll.period.void' && row.user?.email === 'admin@ogami.test',
      ),
    ).toBe(true);

    expect(period.lifecycle_steps.map((step) => [step.key, step.state])).toEqual([
      ['draft', 'done'],
      ['processing', 'done'],
      ['approved', 'done'],
      ['finalized', 'done'],
      ['voided', 'done'],
    ]);
    await expect(adminPage.getByText('Voided', { exact: true }).last()).toBeVisible();
    expect(await adminPage.getByText('Disbursed', { exact: true }).count()).toBe(0);

    const repeatVoid = await adminPage.request.post(
      `${baseUrl()}/api/v1/payroll-periods/${periodId}/void`,
      { headers: await csrfHeaders(adminPage), data: { reason: 'repeat attempt' } },
    );
    expect(repeatVoid.status()).toBe(422);
    const repeatCompute = await adminPage.request.post(
      `${baseUrl()}/api/v1/payroll-periods/${periodId}/compute`,
      { headers: await csrfHeaders(adminPage), data: {} },
    );
    expect(repeatCompute.status()).toBe(422);
    expect((await readPeriod(adminPage, periodId!)).status).toBe('voided');
  } finally {
    networks.forEach((network) => network.stop());
    await Promise.all(actors.map((context) => context.close()));
  }

  for (const network of networks) {
    const expectedGateRejection = network === networks[2]
      ? network.failedRequests.filter((request) =>
        request.method === 'PATCH'
        && request.status === 422
        && request.url.includes(`/payroll-periods/${periodId}/finalize`),
      )
      : [];
    expect(expectedGateRejection).toHaveLength(network === networks[2] ? 1 : 0);
    expect(network.failedRequests.filter((request) => !expectedGateRejection.includes(request))).toEqual([]);
    const expectedGateConsoleError = network === networks[2]
      ? network.errors.filter((error) => error.includes('status of 422 (Unprocessable Content)'))
      : [];
    expect(expectedGateConsoleError).toHaveLength(network === networks[2] ? 1 : 0);
    expect(relevantErrors({
      ...network,
      errors: network.errors.filter((error) => !expectedGateConsoleError.includes(error)),
    })).toEqual([]);
  }
});
