import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

const EMPLOYEE_ID = 'aB1cD2eF';

const incomplete = {
  data: {
    steps: [
      { key: 'profile_completed', label: 'Profile', completed_at: '2026-08-25T08:00:00Z' },
      { key: 'shift_assigned', label: 'Shift', completed_at: null },
      { key: 'leave_balances_initialized', label: 'Leave Balances', completed_at: '2026-08-25T08:00:00Z' },
      { key: 'account_provisioned', label: 'System Account', completed_at: '2026-08-25T08:00:00Z' },
      { key: 'dept_team_notified', label: 'Dept Team Notified', completed_at: null },
      { key: 'gov_ids_recorded', label: 'Government IDs', completed_at: null },
      { key: 'banking_recorded', label: 'Banking Info', completed_at: null },
    ],
    completed_at: null,
    is_complete: false,
  },
};

const completed = {
  data: {
    steps: incomplete.data.steps.map((step) => ({ ...step, completed_at: '2026-08-25T08:00:00Z' })),
    completed_at: '2026-08-25T08:00:00Z',
    is_complete: true,
  },
};

test('HR can complete the department-team onboarding attestation from employee detail', async ({ page }) => {
  let attestationCalled = false;

  await page.route(`**/api/v1/hr/employees/${EMPLOYEE_ID}`, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          id: EMPLOYEE_ID,
          employee_no: 'OGM-2026-0001',
          full_name: 'Test Employee',
          first_name: 'Test',
          last_name: 'Employee',
          status: 'active',
          status_label: 'Active',
          department: { id: 'dept-1', name: 'Production' },
          position: { id: 'position-1', title: 'Operator' },
          date_hired: '2026-01-05',
          employment_type: 'regular',
          pay_type: 'monthly',
          basic_monthly_salary: '20000.00',
          user: null,
        },
      }),
    });
  });

  await page.route(`**/api/v1/hr/employees/${EMPLOYEE_ID}/onboarding*`, async (route) => {
    if (route.request().method() === 'POST') {
      attestationCalled = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(completed) });
      return;
    }

    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(incomplete) });
  });

  await loginAs(page, 'hr', `/hr/employees/${EMPLOYEE_ID}`);

  await expect(page.getByText('Dept Team Notified')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mark team notified' })).toBeVisible();

  await page.getByRole('button', { name: 'Mark team notified' }).click();

  await expect(page.getByText(/Onboarding completed on/)).toBeVisible();
  expect(attestationCalled).toBe(true);
});
