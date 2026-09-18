/**
 * E2E — Admin > Create User, department-first employee flow.
 *
 * Walks the browser through the account-creation flow introduced when user
 * accounts became tied to employee records:
 *
 *   1. The employee dropdown and its search are LOCKED until a department is
 *      picked — and the candidates endpoint is never called without one.
 *   2. Picking a department loads that department's account-less employees.
 *   3. Selecting an employee derives identity from the HR record: the email
 *      field prefills from the employee (and stays editable), while the name
 *      is NEVER typed — there is no name field.
 *   4. Submitting POSTs employee_id + role_id to /admin/users.
 *   5. The one-time temporary password is surfaced in a modal and nothing else.
 *
 * All API calls are mocked (see playwright.config.ts — no backend runs in
 * e2e). These tests exercise the rendered flow, not backend authorization;
 * the Laravel feature tests own that half.
 */
import { test, expect } from './fixtures';
import { loginAs } from './helpers-extended';

const DEPARTMENT_ID = 'dept-hash-1';
const OTHER_DEPARTMENT_ID = 'dept-hash-2';

const ROLES = [
  { id: 'role-finance', name: 'Finance Officer' },
  { id: 'role-ppc', name: 'PPC Head' },
];

const BOTH_DEPARTMENTS = [
  { id: DEPARTMENT_ID, name: 'Finance' },
  { id: OTHER_DEPARTMENT_ID, name: 'Production' },
];

const CANDIDATES = {
  data: [
    {
      id: 'emp-hash-1',
      employee_no: 'OGM-2026-0101',
      full_name: 'Juan Dela Cruz',
      first_name: 'Juan',
      last_name: 'Dela Cruz',
      email: 'juan.delacruz@ogami.ph',
      department: 'Finance',
      position: 'Finance Officer',
      employment_type: 'regular',
    },
    {
      id: 'emp-hash-2',
      employee_no: 'OGM-2026-0102',
      full_name: 'Maria Santos',
      first_name: 'Maria',
      last_name: 'Santos',
      email: null,
      department: 'Finance',
      position: 'Accounting Clerk',
      employment_type: 'probationary',
    },
  ],
};

// Labels carry a required-marker span ("Employee*" is the accessible name),
// and "Find employee" substring-matches "Employee" — so locators are
// role-based with anchored names, never getByLabel.
const departmentSelect = (p: import('@playwright/test').Page) =>
  p.getByRole('combobox', { name: /^Department/ });
const employeeSelect = (p: import('@playwright/test').Page) =>
  p.getByRole('combobox', { name: /^Employee/ });
const roleSelect = (p: import('@playwright/test').Page) =>
  p.getByRole('combobox', { name: /^Role/ });
const searchInput = (p: import('@playwright/test').Page) =>
  p.getByRole('textbox', { name: /Find employee/ });
const emailInput = (p: import('@playwright/test').Page) =>
  p.getByRole('textbox', { name: /^Email/ });

/**
 * Mock the two admin endpoints the create page reads. Registered before
 * `loginAs` so the page mounts against the intended payloads.
 */
async function mockCreatePage(
  page: import('@playwright/test').Page,
  departments: Array<{ id: string; name: string }> = BOTH_DEPARTMENTS,
): Promise<void> {
  await page.route('**/api/v1/admin/users/options', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          statuses: [],
          roles: ROLES,
          departments,
        },
      }),
    });
  });

  // Candidates: echo the requested department + search back into the payload
  // so each test can assert which query the page actually fired.
  await page.route('**/api/v1/admin/users/employee-candidates**', async (route) => {
    const url = new URL(route.request().url());
    const departmentId = url.searchParams.get('department_id') ?? '';
    const search = url.searchParams.get('search') ?? '';

    // Sanity tripwire: the page must never fetch candidates without a
    // department — the backend refuses such calls outright.
    if (!departmentId) {
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'department_id required' }) });
      return;
    }

    if (search) {
      // The search narrows WITHIN the department.
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: CANDIDATES.data.filter((c) =>
            departmentId === DEPARTMENT_ID &&
            (c.employee_no.includes(search) || c.full_name.toLowerCase().includes(search.toLowerCase())),
          ),
        }),
      });
      return;
    }

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: departmentId === DEPARTMENT_ID ? CANDIDATES.data : [],
      }),
    });
  });

  // Create: respond 201 with the one-time temp password.
  await page.route('**/api/v1/admin/users', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) });
      return;
    }
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        message: 'User created.',
        data: {
          id: 'user-hash-new',
          email: 'juan.delacruz@ogami.ph',
          name: 'Juan Dela Cruz',
          temp_password: 'TempPass1!',
        },
      }),
    });
  });
}

test.describe('Admin > Create User (department-first)', () => {

  test('locks employee controls until a department is picked', async ({ page }) => {
    await mockCreatePage(page, [BOTH_DEPARTMENTS[0]]);
    await loginAs(page, 'admin', '/admin/users/create');

    const department = departmentSelect(page);
    const search = searchInput(page);
    const employee = employeeSelect(page);

    // Before a department: search + employee dropdown are disabled, and the
    // dropdown explains itself instead of silently listing nothing.
    await expect(department).toBeEnabled();
    await expect(search).toBeDisabled();
    await expect(employee).toBeDisabled();
    await expect(employee).toContainText('Select a department first');
    await expect(page.getByText('Pick a department to load its employees.')).toBeVisible();

    // No candidates request may fire without a department.
    const candidateCalls: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/admin/users/employee-candidates')) {
        candidateCalls.push(request.url());
      }
    });
    expect(candidateCalls).toEqual([]);
  });

  test('picking a department loads its employees and scopes the search', async ({ page }) => {
    await mockCreatePage(page, [BOTH_DEPARTMENTS[0]]);

    const candidateUrls: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/admin/users/employee-candidates')) {
        candidateUrls.push(request.url());
      }
    });

    await loginAs(page, 'admin', '/admin/users/create');

    await departmentSelect(page).selectOption({ label: 'Finance' });

    // The dropdown populates from the department's account-less employees.
    const employee = employeeSelect(page);
    await expect(employee).toBeEnabled();
    await expect(employee).toContainText('Juan Dela Cruz (OGM-2026-0101)');
    await expect(employee).toContainText('Maria Santos (OGM-2026-0102)');

    // The fired request carried the picked department.
    await expect.poll(() => candidateUrls.length).toBeGreaterThan(0);
    expect(new URL(candidateUrls[0]).searchParams.get('department_id')).toBe(DEPARTMENT_ID);

    // Search narrows within the department: type the employee number.
    const search = searchInput(page);
    await expect(search).toBeEnabled();
    await search.fill('OGM-2026-0101');
    await expect(employee).toContainText('Juan Dela Cruz (OGM-2026-0101)');
    await expect(employee).not.toContainText('Maria Santos (OGM-2026-0102)');

    // Every fired request stayed scoped to the department.
    for (const url of candidateUrls) {
      expect(new URL(url).searchParams.get('department_id')).toBe(DEPARTMENT_ID);
    }
  });

  test('switching departments clears the employee selection and search', async ({ page }) => {
    await mockCreatePage(page);
    await loginAs(page, 'admin', '/admin/users/create');

    await departmentSelect(page).selectOption({ label: 'Finance' });
    await searchInput(page).fill('OGM-2026-0101');
    await employeeSelect(page).selectOption({ label: 'Juan Dela Cruz (OGM-2026-0101)' });
    await expect(page.getByText('Finance Officer · Finance · regular')).toBeVisible();

    // Switch departments: the stale selection must not survive.
    await departmentSelect(page).selectOption({ label: 'Production' });

    await expect(employeeSelect(page)).toHaveValue('');
    await expect(searchInput(page)).toHaveValue('');
    await expect(page.getByText('Finance Officer · Finance · regular')).toHaveCount(0);
  });

  test('selecting an employee prefills email from the HR record and shows identity', async ({ page }) => {
    await mockCreatePage(page);
    await loginAs(page, 'admin', '/admin/users/create');

    await departmentSelect(page).selectOption({ label: 'Finance' });
    await employeeSelect(page).selectOption({ label: 'Juan Dela Cruz (OGM-2026-0101)' });

    // Identity card from the HR record. Scoped to the card's spans because
    // the selected employee's name also appears inside the dropdown's <option>.
    await expect(page.locator('span.font-medium').filter({ hasText: 'Juan Dela Cruz' })).toBeVisible();
    await expect(page.locator('span.font-mono').filter({ hasText: 'OGM-2026-0101' })).toBeVisible();
    await expect(page.getByText('Finance Officer · Finance · regular')).toBeVisible();

    // Email prefilled from the employee record, editable, not required.
    const email = emailInput(page);
    await expect(email).toHaveValue('juan.delacruz@ogami.ph');
    await email.fill('corrected@ogami.ph');
    await expect(email).toHaveValue('corrected@ogami.ph');

    // There is no name field anywhere on the page — identity comes from HR.
    await expect(page.getByLabel('Full Name')).toHaveCount(0);
    await expect(page.getByLabel('Name')).toHaveCount(0);
  });

  test('employee without an HR email requires a typed login email', async ({ page }) => {
    await mockCreatePage(page);
    await loginAs(page, 'admin', '/admin/users/create');

    await departmentSelect(page).selectOption({ label: 'Finance' });
    await employeeSelect(page).selectOption({ label: 'Maria Santos (OGM-2026-0102)' });

    const email = emailInput(page);
    await expect(email).toHaveValue('');

    // Submitting without an email is blocked client-side with a field error…
    await roleSelect(page).selectOption({ label: 'Finance Officer' });
    await page.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('This employee has no email on record. Enter a login email.')).toBeVisible();

    // …and typing one clears the path.
    await email.fill('maria.santos@ogami.ph');
    await page.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByRole('heading', { name: 'Account Created' })).toBeVisible();
  });

  test('submitting creates the account for the employee and shows the one-time password', async ({ page }) => {
    await mockCreatePage(page);
    await loginAs(page, 'admin', '/admin/users/create');

    await departmentSelect(page).selectOption({ label: 'Finance' });
    await employeeSelect(page).selectOption({ label: 'Juan Dela Cruz (OGM-2026-0101)' });
    await roleSelect(page).selectOption({ label: 'Finance Officer' });

    const created: Array<{ url: string; body: Record<string, unknown> }> = [];
    page.on('request', (request) => {
      if (request.url().includes('/api/v1/admin/users') && request.method() === 'POST') {
        created.push({ url: request.url(), body: request.postDataJSON() as Record<string, unknown> });
      }
    });

    await page.getByRole('button', { name: 'Create User' }).click();

    // The POST carried employee_id + role_id — no name, because the account
    // is FOR an employee record.
    await expect.poll(() => created.length).toBe(1);
    expect(created[0].body).toMatchObject({
      employee_id: 'emp-hash-1',
      role_id: 'role-finance',
      email: 'juan.delacruz@ogami.ph',
    });
    expect(created[0].body).not.toHaveProperty('name');

    // One-time temp password modal.
    await expect(page.getByRole('heading', { name: 'Account Created' })).toBeVisible();
    await expect(page.getByText('TempPass1!')).toBeVisible();

    // Done closes the modal and returns to the list.
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(page).toHaveURL(/\/admin\/users$/);
  });

  test('happy-path submit without the welcome email', async ({ page }) => {
    await mockCreatePage(page);
    await loginAs(page, 'admin', '/admin/users/create');

    await departmentSelect(page).selectOption({ label: 'Finance' });
    await employeeSelect(page).selectOption({ label: 'Juan Dela Cruz (OGM-2026-0101)' });
    await roleSelect(page).selectOption({ label: 'PPC Head' });

    // Flip the welcome-email switch off. The checkbox input is sr-only, so
    // click its visible label (rendered by <Switch> as a wrapping <label>).
    const sendWelcome = page.getByRole('checkbox');
    await sendWelcome.locator('xpath=ancestor::label').click();
    await expect(sendWelcome).not.toBeChecked();

    await page.getByRole('button', { name: 'Create User' }).click();

    await expect(page.getByRole('heading', { name: 'Account Created' })).toBeVisible();
    await expect(page.getByText('TempPass1!')).toBeVisible();
  });
});
