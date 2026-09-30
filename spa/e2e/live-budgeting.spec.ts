import { test, expect, type Browser, type BrowserContext, type Page } from '@playwright/test';

/**
 * LIVE budgeting E2E — real backend + real demo accounts, headless Chromium.
 *
 * Roles: finance@ogami.test (maker) → vp@ogami.test (checker),
 * depthead@ / employee@ (denied). Covers the #191–#198 slices end to end:
 * sidebar entry, transfer request, same-role SoD refusal in the UI,
 * VP approval applying the movement, and the 403-equivalent for spenders.
 *
 * Money impact on the shared dev database: one ₱10.00 January move between
 * two lines of the SAME Production budget (department totals unchanged).
 */

const PASSWORD = 'password';
const REASON = `Live E2E virement ${Date.now()}`;

async function login(page: Page, email: string): Promise<void> {
  await page.goto('/sign-in');
  await page.getByLabel('Email').fill(email);
  await page.getByRole('textbox', { name: 'Password' }).fill(PASSWORD);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).not.toHaveURL(/\/sign-in$/, { timeout: 20_000 });
  await page.locator('#main-content').waitFor({ state: 'attached', timeout: 20_000 });
}

async function newContext(browser: Browser): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ baseURL: 'http://localhost' });
  return { context, page: await context.newPage() };
}

async function optionsOf(select: ReturnType<Page['locator']>): Promise<Array<{ value: string; text: string }>> {
  await expect.poll(() => select.locator('option').count(), { timeout: 20_000 }).toBeGreaterThan(1);
  return select.locator('option').evaluateAll((nodes) =>
    nodes.map((node) => ({ value: (node as HTMLOptionElement).value, text: node.textContent ?? '' })),
  );
}

test.describe('live budgeting allocation (role-based)', () => {
  test('finance sees Budgeting nav and the FY overview', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'finance@ogami.test');
      await expect(page.locator('nav').getByText('Budgeting', { exact: true }).first()).toBeVisible({ timeout: 15_000 });
      await page.goto('/budgeting');
      await expect(page.getByRole('heading', { name: 'Budget Overview' })).toBeVisible({ timeout: 20_000 });
      await page.screenshot({ path: 'artifacts/budget-overview-finance.png' });
    } finally {
      await context.close();
    }
  });

  test('finance requests a same-budget January transfer', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'finance@ogami.test');
      await page.goto('/budgeting/transfers/create');
      await expect(page.getByRole('heading', { name: 'Request Budget Transfer' })).toBeVisible({ timeout: 20_000 });

      const fyOptions = await optionsOf(page.getByLabel('Fiscal Year'));
      const fy = fyOptions.find((o) => o.text.includes('2026')) ?? fyOptions.find((o) => o.value !== '');
      await page.getByLabel('Fiscal Year').selectOption(fy!.value);

      const budgetOptions = await optionsOf(page.getByLabel('Source Budget (live only)'));
      const prod = budgetOptions.find((o) => o.text.includes('Production Operating')) ?? budgetOptions.find((o) => o.value !== '');
      await page.getByLabel('Source Budget (live only)').selectOption(prod!.value);
      await page.getByLabel('Destination Budget (live only)').selectOption(prod!.value);

      const fromOptions = await optionsOf(page.getByLabel('Source Line'));
      const from = fromOptions.find((o) => o.value !== '')!;
      await page.getByLabel('Source Line').selectOption(from.value);
      const toOptions = await optionsOf(page.getByLabel('Destination Line'));
      const to = toOptions.find((o) => o.value !== '' && o.value !== from.value)!;
      await page.getByLabel('Destination Line').selectOption(to.value);

      await page.getByLabel('Month Bucket').selectOption('jan');
      await page.getByLabel(/Amount/).fill('10.00');
      await page.getByLabel('Reason').fill(REASON);
      await page.getByRole('button', { name: 'Request Transfer', exact: true }).click();

      await page.waitForURL(/\/budgeting\/transfers$/, { timeout: 20_000 });
      const row = page.getByRole('row').filter({ hasText: REASON });
      await expect(row).toBeVisible({ timeout: 20_000 });
      await expect(row.getByText('Pending', { exact: true })).toBeVisible();
      await page.screenshot({ path: 'artifacts/budget-transfers-pending.png' });
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
      await page.goto('/budgeting/transfers');
      const row = page.getByRole('row').filter({ hasText: REASON });
      await expect(row).toBeVisible({ timeout: 20_000 });
      await row.getByRole('button', { name: 'Approve', exact: true }).click();
      await expect(page.getByText(/different role/i)).toBeVisible({ timeout: 20_000 });
      await expect(row.getByText('Pending', { exact: true })).toBeVisible();
    } finally {
      await context.close();
    }
  });

  test('vp approves and the movement applies', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'vp@ogami.test');
      // VP holds no manage grant: no request affordance.
      await page.goto('/budgeting/transfers');
      await expect(page.getByRole('button', { name: 'Request Transfer', exact: true })).toHaveCount(0);
      const row = page.getByRole('row').filter({ hasText: REASON });
      await expect(row).toBeVisible({ timeout: 20_000 });
      await row.getByRole('button', { name: 'Approve', exact: true }).click();
      await expect(page.getByText(/approved and applied/i)).toBeVisible({ timeout: 20_000 });
      await expect(row.getByText('Approved', { exact: true })).toBeVisible({ timeout: 20_000 });
      await page.screenshot({ path: 'artifacts/budget-transfers-approved.png' });
    } finally {
      await context.close();
    }
  });

  test('department head has no Budgeting nav and meets a 404-equivalent', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'depthead@ogami.test');
      await expect(page.locator('nav').getByText('Budgeting', { exact: true })).toHaveCount(0);
      await page.goto('/budgeting');
      await expect(page.getByText('Page not found')).toBeVisible({ timeout: 20_000 });
    } finally {
      await context.close();
    }
  });

  test('plain employee meets a 404-equivalent on budgeting routes', async ({ browser }) => {
    const { context, page } = await newContext(browser);
    try {
      await login(page, 'employee@ogami.test');
      await page.goto('/budgeting/transfers');
      await expect(page.getByText('Page not found')).toBeVisible({ timeout: 20_000 });
    } finally {
      await context.close();
    }
  });
});
