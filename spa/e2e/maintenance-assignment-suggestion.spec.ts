import { expect, test } from '@playwright/test';
import { mockAuth, ROLES } from './helpers-extended';

for (const width of [1280, 390]) {
  test(`stale attendance assignment reports refresh error at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await mockAuth(page, { ...ROLES.maintenance, roleSlug: 'maintenance_supervisor', roleName: 'Maintenance Supervisor', permissions: [...ROLES.maintenance.permissions, 'maintenance.wo.assign'] });
    await page.route('**/api/v1/maintenance/work-orders/options', (route) => route.fulfill({ json: { data: { types: [], priorities: [], statuses: [] } } }));
    await page.route('**/api/v1/maintenance/work-orders/work-1', (route) => route.fulfill({ json: { data: {
      id: 'work-1', mwo_number: 'MWO-SUGGEST-1', maintainable_type: 'machine', type: 'corrective', priority: 'high',
      status: 'open', available_actions: ['assign'], verification_required: true, cost: '0.00', description: 'Inspect motor',
      maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Test machine' }, assignee: null,
      logs: [], spare_parts: [], started_at: null, completed_at: null, downtime_minutes: 0,
    } } }));
    let changed = false;
    let calls = 0;
    await page.route('**/api/v1/maintenance/work-orders/work-1/assignee-suggestions', (route) => {
      calls++;
      return route.fulfill({ json: { data: { suggested_employee_id: changed ? null : 'tech-1', evaluated_at: '2026-10-09T10:00:00Z', candidates: [{
        id: 'tech-1', employee_no: 'MT-001', name: 'Present Technician', presence: { state: changed ? 'blocked' : 'present', reason: null, reason_code: null },
        active_load: 0, can_assign: !changed, can_attest_presence: false, blocked_reason: changed ? 'Worker has clocked out.' : null,
      }] } } });
    });
    await page.route('**/api/v1/maintenance/work-orders/work-1/assign', (route) => {
      expect(route.request().postDataJSON()).toMatchObject({ employee_id: 'tech-1', expected_assignee_id: null });
      changed = true;
      return route.fulfill({ status: 422, json: { message: 'Worker has clocked out. Refresh suggestions and choose again.', errors: { employee_id: ['Worker has clocked out. Refresh suggestions and choose again.'] } } });
    });
    await page.goto('/maintenance/work-orders/work-1');
    await page.getByLabel('Assignee', { exact: true }).selectOption('tech-1');
    await expect(page.getByRole('option', { name: /Suggested/ })).toHaveCount(1);
    await page.getByRole('button', { name: 'Assign work order', exact: true }).click();
    await expect(page.getByText('Worker has clocked out. Refresh suggestions and choose again.', { exact: true })).toBeVisible();
    await expect.poll(() => calls).toBeGreaterThan(1);
    await expect(page.getByRole('option', { name: /Blocked/ })).toBeDisabled();
  });
}
