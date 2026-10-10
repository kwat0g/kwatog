import { expect, test } from '@playwright/test';
import { mockAuth, ROLES } from './helpers-extended';

test('repeat breakdown notice links machine history and preventive schedules', async ({ page }) => {
  await mockAuth(page, { ...ROLES.maintenance, roleSlug: 'maintenance_supervisor',
    roleName: 'Maintenance Supervisor', permissions: [...ROLES.maintenance.permissions, 'maintenance.schedules.manage'] });
  await page.route('**/api/v1/notifications**', (route) => route.fulfill({ json: {
    data: [{ id: 'repeat-1', type: 'maintenance.repeat_breakdown', read_at: '2026-10-09T12:00:00Z',
      created_at: '2026-10-09T12:00:00Z', data: {
        title: 'Repeated breakdowns: IMM-01', message: 'Review three incidents.',
        link_to: '/maintenance/downtime?search=IMM-01',
        schedule_link: '/maintenance/schedules?maintainable_type=machine&maintainable_id=machine-1',
      } }],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, unread_count: 0 },
  } }));

  await page.goto('/notifications');
  await expect(page.getByRole('button', { name: /Repeated breakdowns: IMM-01/ })).toBeVisible();
  await page.getByRole('button', { name: 'Review schedule' }).click();
  await expect(page).toHaveURL(/\/maintenance\/schedules\?maintainable_type=machine&maintainable_id=machine-1/);
});
