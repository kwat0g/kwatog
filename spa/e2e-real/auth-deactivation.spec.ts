import { expect, test, type Page } from '@playwright/test';
import {
  baseUrl,
  captureNetwork,
  loginAs,
  relevantErrors,
  statefulHeaders,
} from './helpers';

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
  const xsrf = (await page.context().cookies(baseUrl())).find((cookie) => cookie.name === 'XSRF-TOKEN');
  expect(xsrf, 'the browser must hold the Sanctum CSRF cookie').toBeDefined();
  return {
    ...statefulHeaders(),
    'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
  };
}

test('deactivation blocks an already signed-in internal user immediately', async ({ browser }) => {
  const adminContext = await browser.newContext();
  const employeeContext = await browser.newContext();
  const secondEmployeeContext = await browser.newContext();
  const adminPage = await adminContext.newPage();
  const employeePage = await employeeContext.newPage();
  const secondEmployeePage = await secondEmployeeContext.newPage();
  const adminNetwork = captureNetwork(adminPage);
  const employeeNetwork = captureNetwork(employeePage);
  const secondEmployeeNetwork = captureNetwork(secondEmployeePage);
  let employeeId: string | undefined;
  let employeeCsrf: Record<string, string> | undefined;
  let secondEmployeeCsrf: Record<string, string> | undefined;

  try {
    await loginAs(adminPage, 'admin@ogami.test', 'password');
    await loginAs(employeePage, 'employee@ogami.test', 'password');
    await loginAs(secondEmployeePage, 'employee@ogami.test', 'password');
    employeeCsrf = await csrfHeaders(employeePage);
    secondEmployeeCsrf = await csrfHeaders(secondEmployeePage);
    await employeePage.goto(`${baseUrl()}/`);
    await secondEmployeePage.goto(`${baseUrl()}/`);

    const identityUrl = `${baseUrl()}/api/v1/auth/user`;
    const before = await employeePage.request.get(identityUrl, { headers: statefulHeaders() });
    expect(before.status(), 'the employee session works before deactivation').toBe(200);

    const users = await adminPage.request.get(
      `${baseUrl()}/api/v1/admin/users?search=employee%40ogami.test`,
      { headers: statefulHeaders() },
    );
    expect(users.status(), 'the admin can locate the target account').toBe(200);
    const user = ((await users.json()) as { data: Array<{ id: string; email: string }> }).data.find(
      (row) => row.email === 'employee@ogami.test',
    );
    expect(user).toBeDefined();
    employeeId = user?.id;

    const deactivation = await adminPage.request.patch(
      `${baseUrl()}/api/v1/admin/users/${employeeId}/deactivate`,
      { headers: await csrfHeaders(adminPage), data: {} },
    );
    expect(deactivation.status()).toBe(200);

    const after = await employeePage.request.get(identityUrl, { headers: statefulHeaders() });
    expect(after.status(), 'an existing employee session must be rejected immediately').toBe(401);
    const secondAfter = await secondEmployeePage.request.get(identityUrl, { headers: statefulHeaders() });
    expect(secondAfter.status(), 'every existing employee session must be rejected').toBe(401);

    employeeNetwork.stop();
    await employeePage.goto(`${baseUrl()}/dashboard`);
    await expect(employeePage).toHaveURL(/\/sign-in$/);
    secondEmployeeNetwork.stop();
    await secondEmployeePage.goto(`${baseUrl()}/dashboard`);
    await expect(secondEmployeePage).toHaveURL(/\/sign-in$/);
  } finally {
    if (employeeId) {
      const detail = await adminPage.request.get(
        `${baseUrl()}/api/v1/admin/users/${employeeId}`,
        { headers: statefulHeaders() },
      );
      const detailBody = (await detail.json()) as { data?: { is_active?: boolean } };
      if (detail.ok() && detailBody.data?.is_active === false) {
        await adminPage.request.patch(
          `${baseUrl()}/api/v1/admin/users/${employeeId}/activate`,
          { headers: await csrfHeaders(adminPage), data: {} },
        );
      }
    }

    if (employeeCsrf) {
      await employeePage.request.post(`${baseUrl()}/api/v1/auth/logout`, {
        headers: employeeCsrf,
        data: {},
      });
    }
    if (secondEmployeeCsrf) {
      await secondEmployeePage.request.post(`${baseUrl()}/api/v1/auth/logout`, {
        headers: secondEmployeeCsrf,
        data: {},
      });
    }
    adminNetwork.stop();
    employeeNetwork.stop();
    secondEmployeeNetwork.stop();
    await adminContext.close();
    await employeeContext.close();
    await secondEmployeeContext.close();
  }

  expect(adminNetwork.failedRequests).toEqual([]);
  expect(employeeNetwork.failedRequests).toEqual([]);
  expect(relevantErrors(adminNetwork)).toEqual([]);
  expect(relevantErrors(employeeNetwork)).toEqual([]);
  expect(secondEmployeeNetwork.failedRequests).toEqual([]);
  expect(relevantErrors(secondEmployeeNetwork)).toEqual([]);
});
