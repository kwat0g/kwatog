/**
 * Shared Playwright fixtures.
 *
 * The one thing here is an API fallback route, and it exists because of a
 * failure mode that had quietly disabled twenty tests.
 *
 * In E2E there is no backend: `playwright.config.ts` mocks everything and the
 * Vite proxy has nothing behind it. An endpoint a spec has not mocked therefore
 * answers 401 — and the axios interceptor correctly treats a 401 as an expired
 * session, clears the query cache and navigates to /login. So a single endpoint
 * a page acquired *after* its spec was written blanked the page and failed every
 * assertion in that spec, with an error message pointing at whichever locator
 * happened to be checked first. That is what had happened to the chain, payroll
 * and order-to-cash suites.
 *
 * Why a fixture rather than a line in `mockAuth`: Playwright gives priority to
 * the most recently registered matching route. Specs register their mocks at
 * various points — several before calling `loginAs` — so a fallback registered
 * inside `mockAuth` overrode them and made things worse. An `auto` fixture runs
 * before the test body, so every route a spec registers is necessarily later and
 * therefore wins.
 *
 * It answers with an EMPTY payload on purpose. A spec that needs data still has
 * to mock it; this only stops a missing mock from destroying the page underneath
 * assertions about something else. If a test starts passing because of this
 * fixture, the test was asserting nothing.
 */
import { test as base, expect } from '@playwright/test';
export type { Page, Locator } from '@playwright/test';

/*
 * An empty *object*, not an empty list.
 *
 * Almost every API function here ends in `.then(r => r.data.data)`, so `{}`
 * yields `undefined` downstream — which is what "no data" already means
 * throughout the app, and leaves every `x ? … : fallback` guard intact.
 *
 * Returning `{ data: [], meta }` instead looked more helpful and was worse: it
 * made an unmocked response *truthy* but the wrong shape, so
 * `statusCounts ? statusCounts.counts[tile.status] : '—'` — correct code — read
 * `.active` off undefined and crashed the employees page. A fallback must be
 * indistinguishable from absent, not a plausible-looking impostor.
 */
const EMPTY_RESPONSE = {};

export const test = base.extend<{ apiFallback: void }>({
  apiFallback: [
    async ({ page }, use) => {
      await page.route('**/api/v1/**', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(EMPTY_RESPONSE),
        });
      });
      await use();
    },
    { auto: true },
  ],
});

export { expect };
