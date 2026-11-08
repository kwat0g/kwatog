import { test, expect } from '@playwright/test';
import { clearClock, freezeClock, readClock } from './helpers/clock';
import { readOnlyDatabaseJson } from './helpers/database-reader';

test('isolated browser context, gated clock, read-only database, and Mailpit are available', async ({ page, request }) => {
  await page.goto('/sign-in');
  const browserCrypto = await page.evaluate(() => ({
    secureContext: window.isSecureContext,
    randomUUID: typeof window.crypto.randomUUID,
  }));
  expect(browserCrypto).toEqual({ secureContext: true, randomUUID: 'function' });

  const deniedClockRead = await request.get('/api/v1/_e2e/clock', { failOnStatusCode: false });
  expect(deniedClockRead.status(), 'clock control must reject requests without its E2E token').toBe(403);

  const fixedInstant = '2026-04-01T08:15:00+08:00';
  try {
    await freezeClock(request, fixedInstant);
    expect(Date.parse(await readClock(request))).toBe(Date.parse(fixedInstant));
  } finally {
    await clearClock(request);
  }

  const databaseState = await readOnlyDatabaseJson<Array<{ current_user: string; read_only: string }>>(
    "SELECT current_user, current_setting('transaction_read_only') AS read_only",
  );
  expect(databaseState).toEqual([{ current_user: 'ogami_e2e_reader', read_only: 'on' }]);

  const roleCounts = await readOnlyDatabaseJson<Array<{ role_count: number }>>(
    'SELECT COUNT(*)::int AS role_count FROM roles',
  );
  expect(roleCounts[0]?.role_count).toBeGreaterThan(0);

  const mailpitHealth = await fetch('http://mailpit:8025/livez');
  expect(mailpitHealth.status).toBe(200);
});
