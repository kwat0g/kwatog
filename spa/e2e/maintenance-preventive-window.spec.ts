import { expect, test } from '@playwright/test';
import { mockAuth, ROLES } from './helpers-extended';

const schedule = {
  id: 'schedule-1', maintainable_type: 'machine', maintainable_id: 'machine-1',
  maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Press A' },
  description: 'Inspect press', interval_type: 'days', interval_value: 7,
  estimated_duration_minutes: 90, is_active: true, last_performed_at: null,
  next_due_at: '2026-10-10T00:00:00Z', progress: null,
};
const slot = {
  planned_start_at: '2026-10-10T22:00:00+08:00', planned_end_at: '2026-10-10T23:30:00+08:00',
  technician: { id: 'tech-1', employee_no: 'MT-001', name: 'Avery Cruz' },
};

for (const width of [1280, 390]) {
  test(`supervisor reviews and confirms staffed preventive window at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await mockAuth(page, { ...ROLES.maintenance, roleSlug: 'maintenance_supervisor',
      roleName: 'Maintenance Supervisor', permissions: [...ROLES.maintenance.permissions, 'maintenance.schedules.manage', 'maintenance.wo.assign'] });
    await page.route('**/api/v1/maintenance/schedules/schedule-1', route => route.fulfill({ json: { data: schedule } }));
    await page.route('**/api/v1/maintenance/schedules/schedule-1/window-suggestions', route => route.fulfill({
      json: { data: { work_order_id: 'order-1', confirmed_window: null, suggestions: [slot],
        blockers: { schedule: [], production: [], maintenance: [], staffing: [] } } },
    }));
    let posted = false;
    await page.route('**/api/v1/maintenance/work-orders/order-1/window', route => {
      expect(route.request().postDataJSON()).toEqual({ planned_start_at: slot.planned_start_at,
        planned_end_at: slot.planned_end_at, employee_id: 'tech-1' });
      posted = true;
      return route.fulfill({ status: 201, json: { data: { ...slot, work_order_id: 'order-1' } } });
    });

    await page.goto('/maintenance/schedules/schedule-1');
    await expect(page.getByText('90 minutes', { exact: true })).toBeVisible();
    await expect(page.getByText('Avery Cruz · MT-001')).toBeVisible();
    await page.getByRole('button', { name: 'Confirm window with Avery Cruz' }).click();
    await expect.poll(() => posted).toBe(true);
    await expect(page.getByText('Window reserved and technician assigned.')).toBeVisible();
  });
}

test('schedule editor preserves estimated duration', async ({ page }) => {
  await mockAuth(page, { ...ROLES.maintenance, roleSlug: 'maintenance_supervisor',
    roleName: 'Maintenance Supervisor', permissions: [...ROLES.maintenance.permissions, 'maintenance.schedules.manage', 'maintenance.wo.assign'] });
  await page.route('**/api/v1/maintenance/schedules/options', route => route.fulfill({ json: { data: {
    maintainable_types: [{ value: 'machine', label: 'Machine' }], interval_types: [{ value: 'days', label: 'Days' }],
  } } }));
  await page.route('**/api/v1/maintenance/schedules/schedule-1', route => route.fulfill({ json: { data: schedule } }));
  let saved = false;
  await page.route('**/api/v1/maintenance/schedules/schedule-1', route => {
    if (route.request().method() !== 'PUT') return route.fulfill({ json: { data: schedule } });
    expect(route.request().postDataJSON().estimated_duration_minutes).toBe(120);
    saved = true;
    return route.fulfill({ json: { data: { ...schedule, estimated_duration_minutes: 120 } } });
  });
  await page.goto('/maintenance/schedules/schedule-1/edit');
  await expect(page.getByRole('spinbutton', { name: 'Estimated duration (minutes)' })).toHaveValue('90');
  await page.getByRole('spinbutton', { name: 'Estimated duration (minutes)' }).fill('120');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect.poll(() => saved).toBe(true);
});
