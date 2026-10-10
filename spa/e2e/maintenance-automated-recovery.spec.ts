import { readFileSync } from 'node:fs';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { loginAs, statefulHeaders } from '../e2e-real/helpers';

type Actor = { email: string; employee_id: string; name: string };
type Fixture = {
  database: string;
  accounts: Record<'supervisor' | 'technician' | 'absent' | 'manager' | 'warehouse' | 'worker', Actor>;
  production_order: string;
  production_number: string;
  machine: string;
};
type Order = {
  id: string;
  mwo_number: string;
  status: string;
  verification_required: boolean;
  repair_submitted_at: string | null;
  downtime_minutes: number;
  cost: string;
  response_minutes: number | null;
  technician_repair_minutes: number | null;
  verifier: { name: string } | null;
  readiness: { production_work_order: { id: string } | null };
};

const fixturePath = process.env.E2E_MAINTENANCE_RECOVERY_FIXTURE;
if (!fixturePath) throw new Error('Run with node scripts/maintenance-automated-recovery-e2e.cjs; a disposable fixture is required.');
const fixture = JSON.parse(readFileSync(fixturePath, 'utf8')) as Fixture;
if (!/^ogami_test_maintenance_recovery_mr[a-z0-9]+$/.test(fixture.database)) throw new Error('Recovery fixture is not disposable.');
const password = 'Recovery-Test-2026!';

async function actor(browser: Browser, name: keyof Fixture['accounts']): Promise<Page> {
  const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL });
  const page = await context.newPage();
  await loginAs(page, fixture.accounts[name].email, password);
  const response = await page.request.get('/api/v1/auth/user', { headers: statefulHeaders() });
  expect(response.status()).toBe(200);
  expect((await response.json()).data.role.slug).not.toBe('system_admin');
  return page;
}

async function order(page: Page, id: string): Promise<Order> {
  const response = await page.request.get(`/api/v1/maintenance/work-orders/${id}`, { headers: statefulHeaders() });
  expect(response.status(), await response.text()).toBe(200);
  return (await response.json()).data as Order;
}

test('breakdown to independent verified recovery uses non-admin roles and real stock', async ({ browser }) => {
  const manager = await actor(browser, 'manager');
  const supervisor = await actor(browser, 'supervisor');
  const technician = await actor(browser, 'technician');
    await manager.goto(`/production/work-orders/${fixture.production_order}`);
    await manager.getByRole('button', { name: 'Pause', exact: true }).click();
    await manager.getByLabel('Downtime category').selectOption('breakdown');
    await manager.getByRole('textbox', { name: 'Reason', exact: true }).fill('Recovery fixture bearing seized');
    const paused = manager.waitForResponse((response) => response.url().endsWith(`/production/work-orders/${fixture.production_order}/pause`) && response.request().method() === 'POST');
    await manager.getByRole('dialog').getByRole('button', { name: 'Pause work order' }).click();
    expect((await paused).status()).toBe(200);
    await expect(manager.getByRole('button', { name: 'Release resources' }).first()).toBeVisible();

    const list = await supervisor.request.get('/api/v1/maintenance/work-orders?search=Recovery%20fixture%20bearing%20seized', { headers: statefulHeaders() });
    expect(list.status(), await list.text()).toBe(200);
    const matches = (await list.json()).data as Order[];
    expect(matches).toHaveLength(1);
    const id = matches[0].id;
    expect(matches[0]).toMatchObject({ verification_required: true, status: 'open' });
    expect((await order(supervisor, id)).readiness.production_work_order?.id).toBe(fixture.production_order);

    await supervisor.goto(`/maintenance/work-orders/${id}`);
    await expect(supervisor.getByText(/Production still owns this equipment/)).toBeVisible();
    await expect(supervisor.getByRole('link', { name: `Production order ${fixture.production_number}` })).toHaveCount(0);
    await manager.goto(`/maintenance/work-orders/${id}`);
    await expect(manager.getByRole('link', { name: `Production order ${fixture.production_number}` })).toBeVisible();
    await manager.goto(`/production/work-orders/${fixture.production_order}`);
    const suggestions = await supervisor.request.get(`/api/v1/maintenance/work-orders/${id}/assignee-suggestions`, { headers: statefulHeaders() });
    expect(suggestions.status(), await suggestions.text()).toBe(200);
    const choices = (await suggestions.json()).data as { suggested_employee_id: string; candidates: Array<{ id: string; can_assign: boolean; blocked_reason: string | null }> };
    expect(choices.suggested_employee_id).toBe(fixture.accounts.technician.employee_id);
    expect(choices.candidates).toContainEqual(expect.objectContaining({ id: fixture.accounts.absent.employee_id, can_assign: false }));
    await supervisor.getByLabel('Assignee', { exact: true }).selectOption(fixture.accounts.technician.employee_id);
    await expect(supervisor.getByRole('option', { name: /Suggested/ })).toHaveCount(1);
    const assigned = supervisor.waitForResponse((response) => response.url().endsWith(`/maintenance/work-orders/${id}/assign`) && response.request().method() === 'PATCH');
    await supervisor.getByRole('button', { name: 'Assign work order' }).click();
    expect((await assigned).status()).toBe(200);

    await manager.getByRole('button', { name: 'Release resources' }).first().click();
    await manager.getByLabel('Release reason').fill('Machine handed to maintenance');
    const released = manager.waitForResponse((response) => response.url().endsWith(`/production/work-orders/${fixture.production_order}/release-resources`) && response.request().method() === 'POST');
    await manager.getByRole('dialog').getByRole('button', { name: 'Release resources' }).click();
    expect((await released).status()).toBe(200);

    await technician.goto(`/maintenance/work-orders/${id}`);
    await expect(technician.getByText('No equipment blockers.')).toBeVisible();
    await technician.getByRole('button', { name: 'Start', exact: true }).first().click();
    const started = technician.waitForResponse((response) => response.url().endsWith(`/maintenance/work-orders/${id}/start`) && response.request().method() === 'PATCH');
    await technician.getByRole('dialog').getByRole('button', { name: 'Start', exact: true }).click();
    expect((await started).status()).toBe(200);
    await expect(technician.getByText('Recovery downtime (provisional)', { exact: true })).toBeVisible();
    await technician.getByLabel('Work log note').fill('Isolated power and inspected seized bearing');
    await technician.getByRole('button', { name: 'Add work log' }).click();
    await expect(technician.getByText('Isolated power and inspected seized bearing')).toBeVisible();

    await technician.getByRole('button', { name: 'Add part' }).click();
    await technician.getByLabel('Search spare parts').fill('MR-PART');
    await technician.getByRole('button', { name: /Recovery bearing/ }).click();
    await technician.getByLabel('Source location').selectOption({ index: 1 });
    await technician.getByLabel('Quantity (PCS)').fill('2');
    await technician.getByRole('dialog').getByRole('button', { name: 'Add part' }).click();
    await expect(technician.getByRole('button', { name: 'Return Recovery bearing' })).toBeVisible();
    await technician.getByRole('button', { name: 'Return Recovery bearing' }).click();
    await technician.getByLabel('Return quantity').fill('1');
    await technician.getByRole('button', { name: 'Confirm return' }).click();
    await expect.poll(async () => (await order(technician, id)).cost).toBe('25.00');

    for (const [label, value] of [['Diagnosis', 'Bearing seized under load'], ['Repair action', 'Replaced bearing and checked alignment'], ['Functional test result', 'Unloaded trial passed']]) {
      await technician.getByRole('textbox', { name: label, exact: true }).fill(value);
    }
    await technician.getByRole('button', { name: 'Submit repair' }).click();
    await expect(technician.getByRole('heading', { name: 'Awaiting verification' })).toBeVisible();
    await expect(technician.getByRole('button', { name: 'Add work log' })).toHaveCount(0);
    expect((await order(technician, id)).repair_submitted_at).toBeTruthy();

    await manager.goto(`/maintenance/work-orders/${id}`);
    await manager.getByLabel('Rejection reason').fill('Run loaded trial before release');
    await manager.getByRole('button', { name: 'Reject repair' }).click();
    await expect(manager.getByRole('heading', { name: 'Repair evidence' })).toBeVisible();
    expect((await order(manager, id)).status).toBe('in_progress');
    expect((await order(manager, id)).repair_submitted_at).toBeNull();

    await technician.reload();
    for (const [label, value] of [['Diagnosis', 'Bearing seized under load'], ['Repair action', 'Replaced bearing and checked alignment'], ['Functional test result', 'Loaded trial passed for ten minutes']]) {
      await technician.getByRole('textbox', { name: label, exact: true }).fill(value);
    }
    await technician.getByRole('button', { name: 'Submit repair' }).click();
    await expect(technician.getByRole('heading', { name: 'Awaiting verification' })).toBeVisible();
    await manager.reload();
    await manager.getByRole('button', { name: 'Accept recovery' }).click();
    await expect(manager.getByRole('heading', { name: 'Verified recovery' })).toBeVisible();
    const completed = await order(manager, id);
    expect(completed).toMatchObject({ status: 'completed', cost: '25.00', verifier: { name: fixture.accounts.manager.name } });
    expect(completed.downtime_minutes).toBeGreaterThanOrEqual(0);
    expect(completed.response_minutes).not.toBeNull();
    expect(completed.technician_repair_minutes).not.toBeNull();
    await expect(manager.getByText('Parts cost', { exact: true })).toBeVisible();
    await expect(manager.getByText('Recovery downtime (provisional)', { exact: true })).toHaveCount(0);

    await manager.setViewportSize({ width: 390, height: 844 });
    await manager.reload();
    await expect(manager.getByRole('heading', { name: 'Verified recovery' })).toBeVisible();
    await manager.goto(`/production/work-orders/${fixture.production_order}`);
    await expect(manager.getByRole('button', { name: 'Resume', exact: true })).toBeVisible();
    const production = await manager.request.get(`/api/v1/production/work-orders/${fixture.production_order}`, { headers: statefulHeaders() });
    expect((await production.json()).data.status).toBe('paused');
    await manager.getByRole('button', { name: 'Resume', exact: true }).click();
    await manager.getByRole('button', { name: 'Reschedule', exact: true }).click();
    await manager.getByRole('button', { name: 'Approve new schedule' }).click();
    await manager.getByRole('button', { name: 'Resume production' }).click();
    await expect.poll(async () => {
      const response = await manager.request.get(`/api/v1/production/work-orders/${fixture.production_order}`, { headers: statefulHeaders() });
      return (await response.json()).data.status;
    }).toBe('in_progress');
});
