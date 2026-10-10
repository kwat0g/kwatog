import { expect, test, type Page } from '@playwright/test';
import { baseUrl, captureNetwork, loginAs, relevantErrors, statefulHeaders } from './helpers';

type YearEndSubmission = {
  message: string;
  outbox_id: string;
  status: 'pending' | 'published';
};

type ListenerRun = {
  event_type: string;
  listener_class: string;
  queue: { status: string };
  outcome: { status: string; code: string };
  correlation: { outbox_id: string };
};

async function findListenerRun(page: Page, outboxId: string): Promise<ListenerRun | undefined> {
  const params = new URLSearchParams({ attention: 'false', search: outboxId });
  const response = await page.request.get(`${baseUrl()}/api/v1/chain/listener-runs?${params}`, {
    headers: statefulHeaders(),
  });
  expect(response.status()).toBe(200);
  const body = (await response.json()) as { data: { items: ListenerRun[] } };
  return body.data.items.find((run) => run.correlation.outbox_id === outboxId);
}

test('HR queues year-end leave through the page and the listener records completion', async ({ page, browser }) => {
  const network = captureNetwork(page);
  try {
    await loginAs(page, 'hr@ogami.test', 'password');
    await page.goto('/hr/leaves');
    await expect(page.getByRole('heading', { name: 'Leave requests' })).toBeVisible();

    await page.getByRole('button', { name: 'Year-End Leave' }).click();
    await expect(page.getByText('Year-End Leave Processing')).toBeVisible();

    const year = 2020 + (Math.floor(Date.now() / 1000) % 80);
    await page.getByRole('spinbutton', { name: 'Year' }).fill(String(year));
    const responsePromise = page.waitForResponse((response) =>
      response.url().includes('/api/v1/leaves/process-year-end') &&
      response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Run year-end processing' }).click();

    const response = await responsePromise;
    expect(response.status()).toBe(202);
    const submission = (await response.json()) as YearEndSubmission;
    expect(['pending', 'published']).toContain(submission.status);
    expect(submission.outbox_id).toBeTruthy();
    await expect(page.getByText(submission.message)).toBeVisible();

    const recoveryContext = await browser.newContext();
    try {
      const recoveryPage = await recoveryContext.newPage();
      await loginAs(recoveryPage, 'admin@ogami.test', 'password');
      await expect.poll(() => findListenerRun(recoveryPage, submission.outbox_id)).toMatchObject({
        event_type: 'App\\Modules\\Leave\\Events\\YearEndLeaveProcessingRequested',
        listener_class: 'App\\Modules\\Leave\\Listeners\\RunYearEndLeaveOnRequested',
        queue: { status: 'completed' },
        outcome: { status: 'completed', code: 'year_end_leave_processed' },
        correlation: { outbox_id: submission.outbox_id },
      });
    } finally {
      await recoveryContext.close();
    }

    expect(relevantErrors(network)).toEqual([]);
    expect(network.failedRequests).toEqual([]);
  } finally {
    network.stop();
  }
});
