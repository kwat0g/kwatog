import { expect, test } from '@playwright/test';
import { mockAuth, ROLES } from './helpers-extended';

test('maintenance supervisor selects a schedule target without MRP permissions', async ({ page }) => {
  const requests: string[] = [];
  await page.route('**/api/v1/**', route => route.fulfill({ json: { data: [] } }));
  await page.route('**/api/v1/maintenance/schedules/options', route => route.fulfill({
    json: { data: {
      maintainable_types: [{ value: 'machine', label: 'Machine' }],
      interval_types: [{ value: 'days', label: 'Days (calendar)' }],
    } },
  }));
  await page.route('**/api/v1/maintenance/schedules/target-options?*', route => {
    requests.push(route.request().url());
    return route.fulfill({ json: { data: [{ id: 'machine-1', code: 'IMM-01', name: 'Test machine' }] } });
  });
  await mockAuth(page, {
    ...ROLES.maintenance,
    id: 'supervisor-1',
    roleSlug: 'maintenance_supervisor',
    roleName: 'Maintenance Supervisor',
    permissions: ROLES.maintenance.permissions.filter(permission => permission !== 'maintenance.wo.complete')
      .concat('maintenance.wo.assign', 'maintenance.schedules.manage'),
  });

  await page.goto('/maintenance/schedules/create');
  await page.locator('select[name="maintainable_type"]').selectOption('machine');
  await page.getByRole('button', { name: 'Show Target' }).click();
  await page.getByRole('option', { name: 'IMM-01 - Test machine' }).click();
  await expect(page.getByRole('combobox', { name: 'Target' })).toHaveValue('IMM-01 - Test machine');
  expect(requests).toHaveLength(1);
});
