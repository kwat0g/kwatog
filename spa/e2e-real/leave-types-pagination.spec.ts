import { expect, test, type Page } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, relevantErrors, ROLES, statefulHeaders } from './helpers';

type LeaveType = { id: string; code: string; name: string };
type LeaveTypePage = {
  data: LeaveType[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
};

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
  const cookie = (await page.context().cookies(baseUrl())).find(({ name }) => name === 'XSRF-TOKEN');
  expect(cookie, 'HR session holds the Sanctum CSRF cookie').toBeTruthy();
  return { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(cookie!.value) };
}

async function listTypes(page: Page, query: string): Promise<LeaveTypePage> {
  const response = await page.request.get(`${baseUrl()}/api/v1/leaves/types?${query}`, {
    headers: statefulHeaders(),
  });
  expect(response.status(), await response.text()).toBe(200);
  return response.json();
}

test('leave type manager pages server results and resets page when archive scope changes', async ({ page }) => {
  const hr = ROLES.find(({ slug }) => slug === 'hr_officer')!;
  const network = captureNetwork(page);
  let archivedId: string | undefined;

  try {
    await loginAs(page, hr.email, hr.password);
    const csrf = await csrfHeaders(page);
    const fixtures = await listTypes(page, 'per_page=200');
    const archivedType = fixtures.data.find(({ code }) => code === 'AUDPG00100');
    expect(archivedType, 'run AuditLeaveTypePaginationSeeder before this E2E').toBeTruthy();
    const archive = await page.request.delete(`${baseUrl()}/api/v1/leaves/types/${archivedType!.id}`, { headers: csrf });
    expect(archive.status(), await archive.text()).toBe(204);
    archivedId = archivedType!.id;

    const activePage = await listTypes(page, 'page=1&per_page=50');
    const nextPage = await listTypes(page, 'page=2&per_page=50');
    const archivedPage = await listTypes(page, 'page=1&per_page=50&trashed=only');
    expect(activePage.meta.last_page).toBeGreaterThan(2);
    expect(nextPage.data.length).toBeGreaterThan(0);
    expect(archivedPage.data.length).toBeGreaterThan(0);

    await page.goto('/hr/leaves');
    await expect(page.getByRole('heading', { name: 'Leave requests' })).toBeVisible();
    await page.getByRole('button', { name: 'Manage Types' }).click();
    const typesDialog = page.getByRole('dialog', { name: 'Leave Types' });
    await expect(typesDialog.getByText(`Page 1 of ${activePage.meta.last_page}`, { exact: true })).toBeVisible();

    await typesDialog.getByRole('button', { name: 'Next page' }).click();
    await expect(typesDialog.getByText('Page 2 of', { exact: false })).toBeVisible();
    await expect(typesDialog.getByRole('row').filter({ hasText: nextPage.data[0].name })).toBeVisible();

    await typesDialog.getByRole('radio', { name: 'Show archived records only' }).click();
    await expect(typesDialog.getByRole('row').filter({ hasText: archivedPage.data[0].name })).toBeVisible();
    await expect(typesDialog.getByText(`Page 1 of ${archivedPage.meta.last_page}`, { exact: true })).toBeVisible();

    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests).toEqual([]);
  } finally {
    if (archivedId) {
      const csrf = await csrfHeaders(page);
      const restore = await page.request.patch(`${baseUrl()}/api/v1/leaves/types/${archivedId}/restore`, { headers: csrf });
      expect(restore.status(), await restore.text()).toBe(200);
    }
    network.stop();
  }
});
