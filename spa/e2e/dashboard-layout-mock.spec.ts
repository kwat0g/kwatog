import { expect, test } from '@playwright/test';
import { mockAuth, USERS } from './helpers';

test('mockAuth handles dashboard dispatch and the rich layout query', async ({ page }) => {
  await page.route('**/mock-origin', (route) => route.fulfill({ contentType: 'text/html', body: '<html><body></body></html>' }));
  await page.goto('/mock-origin');
  await mockAuth(page, USERS.hr);

  const responses = await page.evaluate(async () => {
    const [dispatch, layout] = await Promise.all([
      fetch('/api/v1/dashboard/dispatch'),
      fetch('/api/v1/dashboard/layout?rich=1'),
    ]);
    return {
      dispatch: { status: dispatch.status, body: await dispatch.text() },
      layout: { status: layout.status, body: await layout.text() },
    };
  });

  expect(responses.dispatch.status).toBe(200);
  expect(JSON.parse(responses.dispatch.body).data.target.path).toBe('/dashboard/hr');
  expect(responses.layout.status).toBe(200);
  expect(JSON.parse(responses.layout.body)).toEqual({ data: [], meta: { layout_version: 'mock-layout-version' } });
});
