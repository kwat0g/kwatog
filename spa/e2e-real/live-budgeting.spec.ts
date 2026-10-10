import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { baseUrl, captureNetwork, relevantErrors, statefulCsrfHeaders, statefulHeaders, type NetworkCapture } from './helpers';

/**
 * Live budgeting E2E — real backend + seeded test accounts, headless Chromium.
 *
 * Roles: finance@ogami.test (maker) → vp@ogami.test (checker),
 * depthead@ / employee@ (denied). Covers the #191–#198 slices end to end:
 * sidebar entry, transfer request, same-role SoD refusal in the UI,
 * VP approval applying the movement, and the 403-equivalent for spenders.
 *
 * Requires the isolated test app from E2E_BASE_URL. Creates one unique ₱10.00
 * January transfer between lines of one Production budget; reset the test DB
 * between full audit runs.
 */

const PASSWORD = 'password';
const REASON = `Live E2E virement ${Date.now()}`;

type BudgetLine = { id: string; jan: string; annual_total: string; account?: { code: string; name: string } };
type BudgetDetail = {
  id: string;
  name: string;
  status: string;
  can_approve: boolean;
  total_allocated: string;
  submitted_by: { id: string; name: string } | null;
  submitted_at: string | null;
  approved_by: { id: string; name: string } | null;
  approved_at: string | null;
  rejected_by: { id: string; name: string } | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  line_items: BudgetLine[];
};
type BudgetAuditEntry = { action: string; user: { email: string; name: string } | null };
type Transfer = {
  id: string;
  status: string;
  can_approve?: boolean;
  month: string;
  amount: string;
  reason: string;
  from_line: { id: string; budget_id: string };
  to_line: { id: string; budget_id: string };
  requested_by: { id: string; name: string } | null;
  approved_by: { id: string; name: string } | null;
};

let transferGate: {
  id: string;
  budgetId: string;
  fromLineId: string;
  toLineId: string;
  budgetTotal: string;
  fromJanuary: string;
  toJanuary: string;
} | undefined;
let budgetGate: { id: string; name: string } | undefined;

async function login(page: Page, email: string): Promise<void> {
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(email);
  await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(/\/sign-in$/, { timeout: 20_000 });
  await page.waitForFunction(() => window.location.pathname !== '/dashboard', undefined, { timeout: 20_000 });
  await page.locator('#main-content').waitFor({ state: 'attached', timeout: 20_000 });
}

async function newContext(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: baseUrl() });
  return { context, page: await context.newPage() };
}

async function assertBudgetNetwork(networks: NetworkCapture[]): Promise<void> {
  for (const network of networks) {
    await network.waitForApiIdle();
    const errors = relevantErrors(network);
    let expected403Count = network.failedRequests.filter(
      ({ status, url }) => status === 403 && url.includes('/api/v1/hr/departments/tree'),
    ).length;
    const unexplainedErrors = errors.filter((error) => {
      if (!error.includes('status of 403 (Forbidden)') || expected403Count < 1) return true;
      return --expected403Count < 0;
    });
    let expected422Count = network.failedRequests.filter(
      ({ status, url }) => status === 422 && (
        /\/api\/v1\/budgets\/[^/?]+\/approve(?:\?|$)/.test(url)
        || /\/api\/v1\/budget-transfers\/[^/?]+\/approve(?:\?|$)/.test(url)
      ),
    ).length;
    const unhandledErrors = unexplainedErrors.filter((error) => {
      if (!error.includes('status of 422 (Unprocessable Content)') || expected422Count < 1) return true;
      return --expected422Count < 0;
    });
    expect(unhandledErrors, 'unexplained browser console and page errors').toEqual([]);
    expect(network.requestFailures, 'failed browser requests').toEqual([]);
  }
  const unexpected = networks.flatMap(({ failedRequests }) => failedRequests).filter(({ method, status, url }) => {
    if (status === 401 && url.includes('/api/v1/auth/user')) return false;
    if (status === 403 && url.includes('/api/v1/hr/departments/tree')) return false;
    if (status === 422 && /\/api\/v1\/budgets\/[^/?]+\/approve(?:\?|$)/.test(url)) return false;
    if (status === 422 && method === 'PUT' && /\/api\/v1\/budgets\/[^/?]+(?:\?|$)/.test(url)) return false;
    if (status === 422 && /\/api\/v1\/budget-transfers\/[^/?]+\/approve(?:\?|$)/.test(url)) return false;
    return true;
  });
  expect(unexpected, 'unexplained failed API responses').toEqual([]);
  networks.forEach((network) => network.stop());
}

async function optionsOf(page: Page, label: string): Promise<string[]> {
  await page.getByRole('combobox', { name: label }).click();
  const listbox = page.getByRole('listbox', { name: label });
  await expect(listbox).toBeVisible();
  const options = await listbox.getByRole('option').allTextContents();
  await page.getByRole('combobox', { name: label }).press('Escape');
  return options.map((option) => option.trim());
}

async function chooseOption(page: Page, label: string, name: string | RegExp): Promise<void> {
  await page.getByRole('combobox', { name: label }).click();
  await page.getByRole('listbox', { name: label }).getByRole('option', { name, exact: true }).click();
}

async function apiData<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(`${baseUrl()}/api/v1${path}`, { headers: statefulHeaders() });
  expect(response.status(), `GET ${path}`).toBe(200);
  return (await response.json() as { data: T }).data;
}

async function budgetFor(page: Page, id: string): Promise<BudgetDetail> {
  return apiData<BudgetDetail>(page, `/budgets/${id}`);
}

async function budgetAudit(page: Page, id: string): Promise<BudgetAuditEntry[]> {
  return apiData<BudgetAuditEntry[]>(
    page,
    `/admin/audit-logs/entity?model_type=Budget&model_id=${encodeURIComponent(id)}&per_page=100`,
  );
}

async function chooseLineAccount(page: Page, index: number, name: string): Promise<void> {
  await page.getByRole('combobox', { name: 'Account' }).nth(index).click();
  await page.getByRole('listbox', { name: 'Account' }).getByRole('option', { name, exact: true }).click();
}

async function assertBudgetAudit(
  adminPage: Page,
  budgetId: string,
  action: string,
  actorEmail: string,
): Promise<void> {
  const trail = await budgetAudit(adminPage, budgetId);
  expect(trail.some((entry) => entry.action === action && entry.user?.email === actorEmail)).toBe(true);
}

async function confirmBudgetAction(
  page: Page,
  title: string,
  openButton: string,
  confirmButton = openButton,
  reason?: string,
): Promise<void> {
  await page.getByRole('button', { name: openButton, exact: true }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: title })).toBeVisible();
  if (reason) await dialog.getByLabel('Return reason').fill(reason);
  await dialog.getByRole('button', { name: confirmButton, exact: true }).click();
}

test.describe('live budgeting allocation (role-based)', () => {
  test('finance sees Budgeting nav and the FY overview', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'finance@ogami.test');
      const network = captureNetwork(page);
      await network.waitForApiIdle(1000);
      const financeGroup = page.getByRole('button', { name: /^Finance/ });
      if (await financeGroup.getAttribute('aria-expanded') !== 'true') {
        await financeGroup.click();
        const menuId = await financeGroup.getAttribute('aria-controls');
        await page.locator(`[id="${menuId}"]`).hover();
      }
      await expect(page.locator('nav').getByText('Budgeting', { exact: true }).first()).toBeVisible({ timeout: 15_000 });
      await page.goto('/budgeting');
      await expect(page.getByRole('heading', { name: 'Budget Overview' })).toBeVisible({ timeout: 20_000 });
      await page.screenshot({ path: 'artifacts/budget-overview-finance.png' });
      await assertBudgetNetwork([network]);
    } finally {
      await context.close();
    }
  });

  test('finance creates, submits, reworks, resubmits, and VP approves a budget', async ({ browser }) => {
    const finance = await newContext(browser);
    const admin = await newContext(browser);
    const networks: NetworkCapture[] = [];
    try {
      await login(finance.page, 'finance@ogami.test');
      const financeNetwork = captureNetwork(finance.page);
      await financeNetwork.waitForApiIdle(1000);
      await login(admin.page, 'admin@ogami.test');
      const adminNetwork = captureNetwork(admin.page);
      await adminNetwork.waitForApiIdle(1000);
      networks.push(financeNetwork, adminNetwork);
      const fiscalYears = await apiData<Array<{ id: string; year: number; status: string }>>(
        finance.page,
        '/budgets/fiscal-years',
      );
      const fiscalYear = fiscalYears.find(({ year, status }) => year === 2026 && status === 'active');
      expect(fiscalYear, 'active FY 2026 exists in the test data').toBeTruthy();

      await finance.page.goto('/budgeting/create');
      await expect(finance.page.getByRole('heading', { name: 'Create Budget' })).toBeVisible({ timeout: 20_000 });
      await expect(finance.page.getByRole('alert').filter({ hasText: 'Department choices could not be loaded' })).toBeVisible();
      await chooseOption(finance.page, 'Fiscal Year', /^FY 2026/);
      await finance.page.getByLabel('Budget Name').fill(`Audit Operating ${Date.now()}`);
      const budgetName = await finance.page.getByLabel('Budget Name').inputValue();
      await finance.page.getByLabel('Budget Type').selectOption('operating');

      const accountControl = finance.page.getByRole('combobox', { name: 'Account' }).nth(0);
      await accountControl.click();
      const accounts = await finance.page.getByRole('listbox', { name: 'Account' }).getByRole('option').allTextContents();
      await accountControl.press('Escape');
      const eligibleAccounts = accounts.map((account) => account.trim()).filter((account) => account && !account.startsWith('Select account'));
      expect(eligibleAccounts.length, 'two active operating leaf accounts are available').toBeGreaterThanOrEqual(2);
      await chooseLineAccount(finance.page, 0, eligibleAccounts[0]);
      await finance.page.getByLabel('Jan amount').nth(0).fill('100.00');
      await finance.page.getByRole('button', { name: 'Add Line', exact: true }).click();
      await chooseLineAccount(finance.page, 1, eligibleAccounts[1]);
      await finance.page.getByLabel('Feb amount').nth(1).fill('25.00');
      await expect(finance.page.getByRole('button', { name: 'Create Budget', exact: true })).toBeEnabled();
      await finance.page.getByRole('button', { name: 'Create Budget', exact: true }).click();
      await finance.page.waitForURL((url) => {
        const lastSegment = url.pathname.split('/').at(-1);
        return url.pathname.startsWith('/budgeting/') && lastSegment !== 'create' && lastSegment !== 'transfers';
      }, { timeout: 20_000 });

      const created = await budgetFor(finance.page, new URL(finance.page.url()).pathname.split('/').at(-1)!);
      expect(created).toMatchObject({ name: budgetName, status: 'draft', total_allocated: '125.00' });
      expect(created.submitted_by).toBeNull();
      expect(created.approved_by).toBeNull();
      expect(created.line_items).toHaveLength(2);
      await assertBudgetAudit(admin.page, created.id, 'created', 'finance@ogami.test');
      budgetGate = { id: created.id, name: created.name };

      // A draft cannot be approved even when the caller has the approval grant.
      const draftApprove = await finance.page.request.post(
        `${baseUrl()}/api/v1/budgets/${created.id}/approve`,
        { headers: await statefulCsrfHeaders(finance.page) },
      );
      expect(draftApprove.status()).toBe(422);
      expect(await budgetFor(finance.page, created.id)).toMatchObject({ status: 'draft', total_allocated: '125.00' });

      await confirmBudgetAction(finance.page, 'Submit budget for approval?', 'Submit for Approval', 'Submit');
      await expect(finance.page.getByText('Budget submitted for approval.', { exact: true })).toBeVisible();
      const submitted = await budgetFor(finance.page, created.id);
      expect(submitted).toMatchObject({
        status: 'submitted',
        submitted_by: { name: 'Ana Reyes' },
        can_approve: false,
        approved_by: null,
        rejected_by: null,
        total_allocated: '125.00',
      });
      expect(submitted.submitted_at).toBeTruthy();
      await assertBudgetAudit(admin.page, created.id, 'updated', 'finance@ogami.test');

      // Maker and same-role checker must not see an unusable approval control.
      await expect(finance.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      const makerApprove = await finance.page.request.post(
        `${baseUrl()}/api/v1/budgets/${created.id}/approve`,
        { headers: await statefulCsrfHeaders(finance.page) },
      );
      expect(makerApprove.status()).toBe(422);
      expect(await makerApprove.text()).toMatch(/submitted a budget cannot approve/i);
      expect(await budgetFor(finance.page, created.id)).toMatchObject({ status: 'submitted', total_allocated: '125.00' });
      const finance2 = await newContext(browser);
      try {
        await login(finance2.page, 'finance2@ogami.test');
        networks.push(captureNetwork(finance2.page));
        await finance2.page.goto(`/budgeting/${created.id}`);
        await expect(finance2.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
        expect((await budgetFor(finance2.page, created.id)).can_approve).toBe(false);
        const sameRoleApprove = await finance2.page.request.post(
          `${baseUrl()}/api/v1/budgets/${created.id}/approve`,
          { headers: await statefulCsrfHeaders(finance2.page) },
        );
        expect(sameRoleApprove.status()).toBe(422);
        expect(await sameRoleApprove.text()).toMatch(/different role/i);
        expect(await budgetFor(finance2.page, created.id)).toMatchObject({ status: 'submitted', total_allocated: '125.00' });
      } finally {
        await finance2.context.close();
      }

      const vp = await newContext(browser);
      try {
        await login(vp.page, 'vp@ogami.test');
        networks.push(captureNetwork(vp.page));
        await vp.page.goto('/budgeting');
        const pendingRow = vp.page.getByRole('row').filter({ hasText: budgetName });
        await expect(pendingRow).toBeVisible({ timeout: 20_000 });
        await expect(pendingRow.getByText('Submitted', { exact: true })).toBeVisible();
        await vp.page.goto(`/budgeting/${created.id}`);
        expect((await budgetFor(vp.page, created.id)).can_approve).toBe(true);
        await expect(vp.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
        await confirmBudgetAction(
          vp.page,
          'Return budget to draft?',
          'Return to draft',
          'Return to draft',
          'Please revise monthly allocations.',
        );
        await expect(vp.page.getByText('Budget returned to draft.', { exact: true })).toBeVisible();
        const returned = await budgetFor(vp.page, created.id);
        expect(returned).toMatchObject({
          status: 'draft',
          rejected_by: { name: 'Kenji Watanabe' },
          rejection_reason: 'Please revise monthly allocations.',
          approved_by: null,
          total_allocated: '125.00',
        });
        expect(returned.rejected_at).toBeTruthy();
        await assertBudgetAudit(admin.page, created.id, 'updated', 'vp@ogami.test');
      } finally {
        await vp.context.close();
      }

      await finance.page.goto(`/budgeting/${created.id}`);
      await expect(finance.page.getByRole('button', { name: 'Edit', exact: true })).toBeVisible();
      await expect(finance.page.getByRole('button', { name: 'Submit for Approval', exact: true })).toBeVisible();
      await finance.page.getByRole('button', { name: 'Edit', exact: true }).click();
      await expect(finance.page.getByRole('heading', { name: 'Edit Budget' })).toBeVisible();
      await finance.page.getByLabel('Mar amount').nth(1).fill('5.00');
      await finance.page.getByRole('button', { name: 'Save Draft', exact: true }).click();
      await finance.page.waitForURL(new RegExp(`/budgeting/${created.id}$`), { timeout: 20_000 });
      const revised = await budgetFor(finance.page, created.id);
      expect(revised).toMatchObject({ status: 'draft', total_allocated: '130.00' });
      await assertBudgetAudit(admin.page, created.id, 'updated', 'finance@ogami.test');

      await confirmBudgetAction(finance.page, 'Submit budget for approval?', 'Submit for Approval', 'Submit');
      await expect(finance.page.getByText('Budget submitted for approval.', { exact: true })).toBeVisible();
      const resubmitted = await budgetFor(finance.page, created.id);
      expect(resubmitted).toMatchObject({
        status: 'submitted',
        submitted_by: { name: 'Ana Reyes' },
        approved_by: null,
        rejected_by: null,
        rejected_at: null,
        rejection_reason: null,
        total_allocated: '130.00',
      });
      await assertBudgetAudit(admin.page, created.id, 'updated', 'finance@ogami.test');

      const finalVp = await newContext(browser);
      try {
        await login(finalVp.page, 'vp@ogami.test');
        networks.push(captureNetwork(finalVp.page));
        await finalVp.page.goto(`/budgeting/${created.id}`);
        expect((await budgetFor(finalVp.page, created.id)).can_approve).toBe(true);
        await expect(finalVp.page.getByRole('button', { name: 'Approve', exact: true })).toBeVisible();
        await confirmBudgetAction(finalVp.page, 'Approve budget?', 'Approve');
        await expect(finalVp.page.getByText('Budget approved and activated.', { exact: true })).toBeVisible();
        const active = await budgetFor(finalVp.page, created.id);
        expect(active).toMatchObject({
          status: 'active',
          submitted_by: { name: 'Ana Reyes' },
          approved_by: { name: 'Kenji Watanabe' },
          can_approve: false,
          total_allocated: '130.00',
        });
        expect(active.approved_at).toBeTruthy();
        await assertBudgetAudit(admin.page, created.id, 'updated', 'vp@ogami.test');
        await expect(finalVp.page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);

        const duplicate = await finalVp.page.request.post(
          `${baseUrl()}/api/v1/budgets/${created.id}/approve`,
          { headers: await statefulCsrfHeaders(finalVp.page) },
        );
        expect(duplicate.status()).toBe(422);
        expect(await budgetFor(finalVp.page, created.id)).toMatchObject({ status: 'active', total_allocated: '130.00' });
        const activeEdit = await finance.page.request.put(
          `${baseUrl()}/api/v1/budgets/${created.id}`,
          { headers: await statefulCsrfHeaders(finance.page), data: { name: 'must not change active budget' } },
        );
        expect(activeEdit.status()).toBe(422);
        expect(await budgetFor(finance.page, created.id)).toMatchObject({ name: budgetName, status: 'active' });
      } finally {
        await finalVp.context.close();
      }
      await assertBudgetNetwork(networks);
    } finally {
      await finance.context.close();
      await admin.context.close();
    }
  });

  test('finance requests a same-budget January transfer', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'finance@ogami.test');
      const network = captureNetwork(page);
      await network.waitForApiIdle(1000);
      await page.goto('/budgeting/transfers/create');
      await expect(page.getByRole('heading', { name: 'Request Budget Transfer' })).toBeVisible({ timeout: 20_000 });

      expect(budgetGate, 'the real budget lifecycle completed first').toBeTruthy();
      const budget = budgetGate!;
      const before = await budgetFor(page, budget.id);
      const sourceLine = before.line_items.find((line) => Number(line.jan) >= 10 && line.account);
      const destinationLine = before.line_items.find((line) => line.id !== sourceLine?.id && line.account);
      expect(sourceLine, 'a source line has at least ₱10 January allocation').toBeTruthy();
      expect(destinationLine, 'a different destination line exists').toBeTruthy();

      await chooseOption(page, 'Fiscal Year', /^FY 2026/);
      await chooseOption(page, 'Source Budget (live only)', new RegExp(budget.name));
      await chooseOption(page, 'Destination Budget (live only)', new RegExp(budget.name));

      const fromOptions = await optionsOf(page, 'Source Line');
      const from = fromOptions.find((option) => option.startsWith(`${sourceLine!.account!.code} — ${sourceLine!.account!.name}`));
      expect(from, 'the selected source line is visible').toBeTruthy();
      await chooseOption(page, 'Source Line', from!);
      const toOptions = await optionsOf(page, 'Destination Line');
      const to = toOptions.find((option) => option.startsWith(`${destinationLine!.account!.code} — ${destinationLine!.account!.name}`));
      expect(to, 'the selected destination line is visible').toBeTruthy();
      await chooseOption(page, 'Destination Line', to!);

      await page.getByLabel('Month Bucket').selectOption('jan');
      await page.getByLabel(/Amount/).fill('10.00');
      await page.getByLabel('Reason').fill(REASON);
      await page.getByRole('button', { name: 'Request Transfer', exact: true }).click();

      await page.waitForURL(/\/budgeting\/transfers$/, { timeout: 20_000 });
      const row = page.getByRole('row').filter({ hasText: REASON });
      await expect(row).toBeVisible({ timeout: 20_000 });
      await expect(row.getByText('Pending', { exact: true })).toBeVisible();

      const transfer = (await apiData<Transfer[]>(page, '/budget-transfers?per_page=100'))
        .find((candidate) => candidate.reason === REASON);
      expect(transfer).toMatchObject({ status: 'pending', month: 'jan', amount: '10.00', approved_by: null });
      expect(transfer?.requested_by?.name).toBeTruthy();
      expect(transfer?.from_line).toMatchObject({ id: sourceLine!.id, budget_id: budget.id });
      expect(transfer?.to_line).toMatchObject({ id: destinationLine!.id, budget_id: budget.id });
      const afterRequest = await budgetFor(page, budget.id);
      expect(afterRequest.line_items.find(({ id }) => id === sourceLine!.id)?.jan).toBe(sourceLine!.jan);
      expect(afterRequest.line_items.find(({ id }) => id === destinationLine!.id)?.jan).toBe(destinationLine!.jan);
      transferGate = {
        id: transfer!.id,
        budgetId: budget.id,
        fromLineId: sourceLine!.id,
        toLineId: destinationLine!.id,
        budgetTotal: before.total_allocated,
        fromJanuary: sourceLine!.jan,
        toJanuary: destinationLine!.jan,
      };
      await page.screenshot({ path: 'artifacts/budget-transfers-pending.png' });
      await assertBudgetNetwork([network]);
    } finally {
      await context.close();
    }
  });

  test('a second finance officer cannot approve either (role SoD live)', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      // finance2@ holds the same finance_officer role as the requester but is
      // a different user: refusal must name the ROLE rule, not the user rule.
      await login(page, 'finance2@ogami.test');
      const network = captureNetwork(page);
      await network.waitForApiIdle(1000);
      await page.goto('/budgeting/transfers');
      const row = page.getByRole('row').filter({ hasText: REASON });
      await expect(row).toBeVisible({ timeout: 20_000 });
      await expect(row.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0);
      await expect(row.getByText(/approval requires a different role/i)).toBeVisible();
      await expect(row.getByText('Pending', { exact: true })).toBeVisible();
      expect(transferGate, 'the maker step completed').toBeTruthy();
      const denied = await page.request.post(
        `${baseUrl()}/api/v1/budget-transfers/${transferGate!.id}/approve`,
        { headers: await statefulCsrfHeaders(page) },
      );
      expect(denied.status()).toBe(422);
      expect(await denied.text()).toMatch(/different role/i);
      const transfer = await apiData<Transfer>(page, `/budget-transfers/${transferGate!.id}`);
      expect(transfer).toMatchObject({ status: 'pending', can_approve: false });
      const budget = await budgetFor(page, transferGate!.budgetId);
      expect(budget.line_items.find(({ id }) => id === transferGate!.fromLineId)?.jan).toBe(transferGate!.fromJanuary);
      expect(budget.line_items.find(({ id }) => id === transferGate!.toLineId)?.jan).toBe(transferGate!.toJanuary);
      await assertBudgetNetwork([network]);
    } finally {
      await context.close();
    }
  });

  test('vp approves and the movement applies', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'vp@ogami.test');
      const network = captureNetwork(page);
      await network.waitForApiIdle(1000);
      // VP holds no manage grant: no request affordance.
      await page.goto('/budgeting/transfers');
      await expect(page.getByRole('button', { name: 'Request Transfer', exact: true })).toHaveCount(0);
      const row = page.getByRole('row').filter({ hasText: REASON });
      await expect(row).toBeVisible({ timeout: 20_000 });
      await row.getByRole('button', { name: 'Approve', exact: true }).click();
      await expect(page.getByText(/approved and applied/i)).toBeVisible({ timeout: 20_000 });
      await expect(row.getByText('Approved', { exact: true })).toBeVisible({ timeout: 20_000 });
      expect(transferGate, 'the maker and SoD checks completed').toBeTruthy();
      const transfer = await apiData<Transfer>(page, `/budget-transfers/${transferGate!.id}`);
      expect(transfer.status).toBe('approved');
      expect(transfer.amount).toBe('10.00');
      expect(transfer.approved_by?.name).toBeTruthy();
      const approvedBudget = await budgetFor(page, transferGate!.budgetId);
      expect(approvedBudget.total_allocated).toBe(transferGate!.budgetTotal);
      expect(Number(approvedBudget.line_items.find(({ id }) => id === transferGate!.fromLineId)?.jan)).toBe(Number(transferGate!.fromJanuary) - 10);
      expect(Number(approvedBudget.line_items.find(({ id }) => id === transferGate!.toLineId)?.jan)).toBe(Number(transferGate!.toJanuary) + 10);
      const duplicate = await page.request.post(
        `${baseUrl()}/api/v1/budget-transfers/${transferGate!.id}/approve`,
        { headers: await statefulCsrfHeaders(page) },
      );
      expect(duplicate.status()).toBe(422);
      expect(await duplicate.text()).toMatch(/only pending/i);
      const unchanged = await budgetFor(page, transferGate!.budgetId);
      expect(Number(unchanged.line_items.find(({ id }) => id === transferGate!.fromLineId)?.jan)).toBe(Number(transferGate!.fromJanuary) - 10);
      expect(Number(unchanged.line_items.find(({ id }) => id === transferGate!.toLineId)?.jan)).toBe(Number(transferGate!.toJanuary) + 10);
      await page.screenshot({ path: 'artifacts/budget-transfers-approved.png' });
      await assertBudgetNetwork([network]);
    } finally {
      await context.close();
    }
  });

  test('department head has no Budgeting nav and meets a 404-equivalent', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'depthead@ogami.test');
      const network = captureNetwork(page);
      await network.waitForApiIdle(1000);
      await expect(page.locator('nav').getByText('Budgeting', { exact: true })).toHaveCount(0);
      await page.goto('/budgeting');
      await expect(page.getByText('Page not found')).toBeVisible({ timeout: 20_000 });
      await assertBudgetNetwork([network]);
    } finally {
      await context.close();
    }
  });

  test('plain employee meets a 404-equivalent on budgeting routes', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'employee@ogami.test');
      const network = captureNetwork(page);
      await network.waitForApiIdle(1000);
      await page.goto('/budgeting/transfers');
      await expect(page.getByText('Page not found')).toBeVisible({ timeout: 20_000 });
      await assertBudgetNetwork([network]);
    } finally {
      await context.close();
    }
  });
});
