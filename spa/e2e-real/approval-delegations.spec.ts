import { expect, test, type Page } from '@playwright/test';
import { localIsoDate } from '../src/lib/formatDate';
import { baseUrl, captureNetwork, loginAs, relevantErrors, statefulHeaders } from './helpers';

interface Delegation {
  id: string;
  role_slug: string;
  starts_at: string;
  ends_at: string;
  reason: string | null;
  is_active: boolean;
  delegate: { id: string; name: string };
}

async function csrfHeaders(page: Page): Promise<Record<string, string>> {
  const cookie = (await page.context().cookies(baseUrl())).find(({ name }) => name === 'XSRF-TOKEN');
  expect(cookie, 'session holds the Sanctum CSRF cookie').toBeTruthy();
  return { ...statefulHeaders(), 'X-XSRF-TOKEN': decodeURIComponent(cookie!.value) };
}

test('finance officer creates and revokes approval cover from profile menu', async ({ page }) => {
  await loginAs(page, 'finance@ogami.test', 'password');
  const csrf = await csrfHeaders(page);
  const network = captureNetwork(page);
  let createdId: string | undefined;
  let revoked = false;

  try {
    await page.goto('/dashboard/finance');
    await page.getByRole('button', { name: /Profile menu/ }).click();
    await page.getByRole('menuitem', { name: 'Approval cover' }).click();
    await expect(page).toHaveURL(/\/approvals\/delegations$/);
    await expect(page.getByRole('heading', { name: 'Approval cover', exact: true })).toBeVisible();

    const candidateSearch = page.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/approval-delegations/candidates' &&
      response.request().method() === 'GET',
    );
    await page.getByLabel('Search active colleagues').fill('employee@ogami.test');
    const candidatesResponse = await candidateSearch;
    expect(candidatesResponse.status(), await candidatesResponse.text()).toBe(200);
    const candidates = (await candidatesResponse.json() as {
      data: Array<{ id: string; name: string; role: string | null }>;
    }).data;
    expect(candidates).toHaveLength(1);
    const candidate = candidates[0]!;

    const previousResponse = await page.request.get(`${baseUrl()}/api/v1/approval-delegations`, {
      headers: statefulHeaders(),
    });
    expect(previousResponse.status(), await previousResponse.text()).toBe(200);
    const previous = (await previousResponse.json() as { data: Delegation[] }).data;
    for (const delegation of previous) {
      if (delegation.is_active && delegation.delegate.id === candidate.id && delegation.reason?.startsWith('Browser audit ')) {
        const response = await page.request.delete(`${baseUrl()}/api/v1/approval-delegations/${delegation.id}`, {
          headers: csrf,
        });
        expect(response.status(), await response.text()).toBe(200);
      }
    }

    await page.getByLabel(/Delegate/).selectOption(candidate.id);
    const startsAt = new Date();
    startsAt.setHours(0, 0, 0, 0);
    startsAt.setDate(startsAt.getDate() + 1 + Math.floor(Math.random() * 365));
    const endsAt = new Date(startsAt);
    endsAt.setDate(endsAt.getDate() + 1);
    const reason = `Browser audit ${Date.now()}`;
    await page.getByLabel(/Start date/).fill(localIsoDate(startsAt));
    await page.getByLabel(/End date/).fill(localIsoDate(endsAt));
    await page.getByLabel('Reason (optional)').fill(reason);
    const payload = {
      delegate_user_id: candidate.id,
      role_slug: 'finance_officer',
      starts_at: localIsoDate(startsAt),
      ends_at: localIsoDate(endsAt),
      reason,
    };

    const createResponse = page.waitForResponse((response) =>
      new URL(response.url()).pathname === '/api/v1/approval-delegations' &&
      response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Set up approval cover' }).click();
    const created = await createResponse;
    expect(created.status(), await created.text()).toBe(201);
    const createdBody = await created.json() as { data: Delegation };
    createdId = createdBody.data.id;
    const concurrentRetries = await Promise.all([
      page.request.post(`${baseUrl()}/api/v1/approval-delegations`, { headers: csrf, data: payload }),
      page.request.post(`${baseUrl()}/api/v1/approval-delegations`, { headers: csrf, data: payload }),
    ]);
    const retryBodies = await Promise.all(concurrentRetries.map((response) => response.json() as Promise<{ data: Delegation }>));
    expect(concurrentRetries.map((response) => response.status())).toEqual([200, 200]);
    expect(retryBodies.map((body) => body.data.id)).toEqual([createdId, createdId]);
    expect(createdBody.data).toMatchObject({
      role_slug: 'finance_officer',
      starts_at: localIsoDate(startsAt),
      ends_at: localIsoDate(endsAt),
      reason,
      is_active: true,
      delegate: { id: candidate.id },
    });
    await expect(page.getByText('Approval cover set up.')).toBeVisible();

    const row = page.locator('article').filter({ hasText: reason });
    await expect(row).toContainText(candidate.name);
    await expect(row).toContainText('Scheduled');
    const listResponse = await page.request.get(`${baseUrl()}/api/v1/approval-delegations`, {
      headers: statefulHeaders(),
    });
    expect(listResponse.status(), await listResponse.text()).toBe(200);
    const listed = (await listResponse.json() as { data: Delegation[] }).data
      .find((delegation) => delegation.id === createdId);
    expect(listed).toMatchObject({ id: createdId, is_active: true, reason, role_slug: 'finance_officer' });

    await row.getByRole('button', { name: 'Revoke' }).click();
    const dialog = page.getByRole('dialog', { name: 'Revoke approval cover?' });
    await expect(dialog).toContainText(candidate.name);
    const revokeResponse = page.waitForResponse((response) =>
      new URL(response.url()).pathname === `/api/v1/approval-delegations/${createdId}` &&
      response.request().method() === 'DELETE',
    );
    await dialog.getByRole('button', { name: 'Revoke cover' }).click();
    const revokedResponse = await revokeResponse;
    expect(revokedResponse.status(), await revokedResponse.text()).toBe(200);
    revoked = true;
    await expect(page.locator('article').filter({ hasText: reason })).toContainText('Revoked');

    const delayedRetry = await page.request.post(`${baseUrl()}/api/v1/approval-delegations`, {
      headers: csrf,
      data: payload,
    });
    expect(delayedRetry.status()).toBe(422);
    expect((await delayedRetry.json()).message).toBe('This approval cover was already revoked. Choose a new date range.');

    const finalResponse = await page.request.get(`${baseUrl()}/api/v1/approval-delegations`, {
      headers: statefulHeaders(),
    });
    expect(finalResponse.status(), await finalResponse.text()).toBe(200);
    const finalDelegation = (await finalResponse.json() as { data: Delegation[] }).data
      .find((delegation) => delegation.id === createdId);
    expect(finalDelegation).toMatchObject({ id: createdId, is_active: false, reason });

    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests).toEqual([]);
  } finally {
    if (createdId && !revoked) {
      await page.request.delete(`${baseUrl()}/api/v1/approval-delegations/${createdId}`, {
        headers: csrf,
      }).catch(() => undefined);
    }
    network.stop();
  }
});
