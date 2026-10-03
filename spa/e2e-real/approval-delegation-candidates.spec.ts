import { expect, test } from '@playwright/test';
import { captureNetwork, loginAs, relevantErrors } from './helpers';

test('department head candidate search only offers users from their department', async ({ page }) => {
  const network = captureNetwork(page);

  try {
    await loginAs(page, 'depthead@ogami.test', 'password');
    await page.goto('/approvals/delegations');
    await expect(page.getByRole('heading', { name: 'Approval cover', exact: true })).toBeVisible();
    for (const viewport of [
      { width: 1440, height: 900 },
      { width: 768, height: 1024 },
      { width: 390, height: 844 },
    ]) {
      await page.setViewportSize(viewport);
      const { documentWidth, viewportWidth } = await page.evaluate(() => ({
        documentWidth: document.documentElement.scrollWidth,
        viewportWidth: document.documentElement.clientWidth,
      }));
      expect(documentWidth, `no horizontal page overflow at ${viewport.width}px`).toBeLessThanOrEqual(viewportWidth);
      await expect(page.getByLabel('Search active colleagues')).toBeVisible();
    }

    const searchResponse = page.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/approval-delegations/candidates' &&
      response.request().method() === 'GET',
    );
    await page.getByLabel('Search active colleagues').fill('finance@ogami.test');
    const response = await searchResponse;
    expect(response.status(), await response.text()).toBe(200);

    const candidates = (await response.json() as {
      data: Array<{ id: string; name: string; role: string | null }>;
    }).data;
    expect(candidates).toEqual([]);
    await expect(page.getByRole('status')).toContainText('No active colleague matched that search.');

    const sameDepartmentSearch = page.waitForResponse((candidateResponse) =>
      new URL(candidateResponse.url()).pathname === '/api/v1/approval-delegations/candidates' &&
      candidateResponse.request().method() === 'GET',
    );
    await page.getByLabel('Search active colleagues').fill('employee@ogami.test');
    const sameDepartmentResponse = await sameDepartmentSearch;
    expect(sameDepartmentResponse.status(), await sameDepartmentResponse.text()).toBe(200);
    const sameDepartmentCandidates = (await sameDepartmentResponse.json() as {
      data: Array<{ id: string; name: string; role: string | null }>;
    }).data;
    expect(sameDepartmentCandidates).toHaveLength(1);
    await page.getByLabel('Delegate').selectOption(sameDepartmentCandidates[0]!.id);

    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests).toEqual([]);
  } finally {
    network.stop();
  }
});
