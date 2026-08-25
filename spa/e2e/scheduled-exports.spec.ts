/**
 * M011-F04 / M011-F09 — scheduled-export create flow.
 *
 * The page used to promise scheduling in its empty state while offering no way
 * to create one, so the API's create endpoint had no caller at all. This spec
 * walks the affordance the way an operator does: open the modal from the page
 * header, confirm it loaded the module's authoritative column list, submit, and
 * confirm the next-run feedback and the refreshed list.
 */
import { test, expect } from './fixtures';
import { loginAs } from './helpers-extended';

const COLUMNS = {
  data: {
    module: 'hr.employees',
    selected: ['employee_no', 'full_name'],
    columns: [
      { key: 'employee_no', label: 'Employee No.', default: true, format: 'text' },
      { key: 'full_name', label: 'Name', default: true, format: 'text' },
      { key: 'department', label: 'Department', default: true, format: 'text' },
    ],
  },
};

const CREATED = {
  id: 'sched01',
  name: 'Active employees — daily',
  module: 'hr.employees',
  columns: ['employee_no', 'full_name'],
  filters: {},
  format: 'xlsx',
  frequency: 'daily',
  day_of_week: null,
  day_of_month: null,
  time_of_day: '06:00',
  recipients: ['hr@ogami.test'],
  is_active: true,
  last_run_at: null,
  next_run_at: '2026-08-27T06:00:00.000000Z',
  last_error: null,
  processing_until: null,
  owner: { id: 'adm0001', name: 'System Administrator' },
  created_at: '2026-08-26T01:00:00.000000Z',
  deleted_at: null,
};

function listBody(rows: unknown[]) {
  return JSON.stringify({
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 25,
      total: rows.length,
      from: rows.length > 0 ? 1 : null,
      to: rows.length > 0 ? rows.length : null,
    },
    links: { first: null, last: null, prev: null, next: null },
  });
}

test.describe('scheduled export creation', () => {
  test('an operator can create a schedule and see its next run', async ({ page }) => {
    let created = false;
    let postedBody: Record<string, unknown> | null = null;

    await page.route('**/api/v1/scheduled-exports*', async (route) => {
      const request = route.request();
      if (request.method() === 'POST') {
        postedBody = request.postDataJSON() as Record<string, unknown>;
        created = true;
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({ data: CREATED }),
        });
        return;
      }
      if (request.method() !== 'GET') {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: listBody(created ? [CREATED] : []),
      });
    });

    await page.route('**/api/v1/exports/hr.employees/columns*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(COLUMNS),
      });
    });

    await loginAs(page, 'admin', '/admin/scheduled-exports');

    // Empty state first — the page must still offer the create affordance.
    await expect(page.getByText('No scheduled exports yet')).toBeVisible();
    await page.getByRole('button', { name: 'New schedule' }).click();

    // The modal must read the module's real column contract, not a local list.
    await expect(page.getByText('New scheduled export')).toBeVisible();
    await expect(page.getByText('Employee No.')).toBeVisible();
    await expect(page.getByText('Department')).toBeVisible();

    await page.getByLabel('Schedule name').fill('Active employees — daily');
    await page.getByLabel('Recipients').fill('hr@ogami.test');
    await page.getByRole('button', { name: 'Create schedule' }).click();

    await expect(page.getByText(/Saved\. Next run:/)).toBeVisible();
    expect(postedBody).not.toBeNull();
    expect(postedBody).toMatchObject({
      module: 'hr.employees',
      frequency: 'daily',
      time_of_day: '06:00',
      recipients: ['hr@ogami.test'],
    });
    // The selection sent to the server must be the module's own keys.
    expect(postedBody!.columns).toEqual(['employee_no', 'full_name']);

    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByText('Active employees — daily')).toBeVisible();
  });

  test('a create failure surfaces the server field error instead of a silent close', async ({ page }) => {
    await page.route('**/api/v1/scheduled-exports*', async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({
            message: 'The given data was invalid.',
            errors: { module: ['Your account cannot export module [hr.employees].'] },
          }),
        });
        return;
      }
      if (route.request().method() !== 'GET') {
        await route.continue();
        return;
      }
      await route.fulfill({ status: 200, contentType: 'application/json', body: listBody([]) });
    });

    await page.route('**/api/v1/exports/hr.employees/columns*', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(COLUMNS) });
    });

    await loginAs(page, 'admin', '/admin/scheduled-exports');
    await page.getByRole('button', { name: 'New schedule' }).click();
    await page.getByLabel('Schedule name').fill('Rejected schedule');
    await page.getByLabel('Recipients').fill('hr@ogami.test');
    await page.getByRole('button', { name: 'Create schedule' }).click();

    // A rejected capability must be readable in the UI, not only in the log.
    await expect(page.getByRole('alert')).toContainText('cannot export module');
  });
});
