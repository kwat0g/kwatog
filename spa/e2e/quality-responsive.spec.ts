import { expect, test } from './fixtures';
import { loginAs } from './helpers-extended';

/**
 * W2.1 — the Quality module's width contracts, asserted in a browser.
 *
 * The Phase 0 harness measures these pages, but it is a script somebody runs;
 * it is not a gate. This is the gate for the three defects the Quality pass
 * found, each stated as the geometry that was wrong rather than the class that
 * fixes it:
 *
 *  1. a form action row wider than the container it sits in (F-020, F-021);
 *  2. a breadcrumb trail wider than its header slot, painting over the search
 *     control beside it at 768 (F-019);
 *  3. the page itself scrolling sideways (the hold-everything assertion).
 *
 * Measured, not inferred: jsdom has no layout, so none of this can be asserted
 * in a unit test. Every number here comes from getBoundingClientRect in a real
 * engine.
 */

const FORM_ROUTES = [
  ['/quality/ncrs/new', 'Open NCR'],
  ['/quality/ncr-templates/new', 'New NCR template'],
  ['/quality/calibration/new', 'New calibration instrument'],
] as const;

/**
 * Well-shaped empty payloads for the lookups these forms fire.
 *
 * The shared `apiFallback` answers `{}` and these helpers return
 * `response.data.data` verbatim, so an unmocked lookup hands React Query
 * `undefined` and logs the console error the diagnostics fixture fails on (the
 * F-018 shape). An empty ARRAY is the safe generic: every consumer here either
 * `.map`s it or reads a missing property off it. Registered before `loginAs`
 * because the requests fire during its first navigation.
 */
async function mockQualityLookups(page: import('@playwright/test').Page) {
  const emptyObject = (route: import('@playwright/test').Route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {} }) });

  // The option lookups answer an object of lists, and the pages call `.find()`
  // on the lists directly (`inspectionOptions.data.sampling_methods.find`), so a
  // generic empty ARRAY here is a crash, not a fallback.
  await page.route('**/api/v1/quality/inspections/options', emptyObject);
  await page.route('**/api/v1/quality/ncrs/options', emptyObject);
  await page.route('**/api/v1/quality/ncr-templates/options', emptyObject);
  await page.route('**/api/v1/quality/calibration/options', emptyObject);
  await page.route('**/api/v1/quality/ncr-templates/active', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }),
  );
  await page.route('**/api/v1/quality/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }),
  );
  await page.route('**/api/v1/crm/products/options**', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
    }),
  );
}

/**
 * The page's action row, found by the sticky bar itself rather than by a button
 * label — every one of these forms labels its buttons differently, and a label
 * is exactly the kind of detail that makes a test rot.
 */
async function actionRow(page: import('@playwright/test').Page) {
  const bar = page.locator('main [class*="safe-area-pb"]').first();
  await expect(bar).toBeVisible();

  await expect(bar.getByRole('button').first()).toBeVisible();

  return bar.evaluate((node) => {
    const row = node.getBoundingClientRect();
    const parent = node.parentElement?.getBoundingClientRect();
    return {
      row: Math.round(row.width),
      parent: parent ? Math.round(parent.width) : null,
      cls: node.className.slice(0, 80),
    };
  });
}

for (const width of [390, 1440] as const) {
  test(`quality forms keep their action row inside its container @${width}`, async ({ page }) => {
    await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
    await mockQualityLookups(page);
    await loginAs(page, 'qc', FORM_ROUTES[0][0]);

    for (const [route, submitLabel] of FORM_ROUTES) {
      if (!page.url().endsWith(route)) await page.goto(route);
      await expect(page.getByRole('heading', { name: submitLabel, level: 1 })).toBeVisible();

      const measured = await actionRow(page);
      expect(measured, `${route} @${width}: no action row found`).not.toBeNull();
      // Before the fix this was 375 against a 335 parent on /quality/ncrs/new
      // (+40) and 367 against 335 on /quality/ncr-templates/new (+32) — the row
      // hung out of its container at every width.
      expect(
        measured!.row,
        `${route} @${width}: action row ${measured!.row}px in a ${measured!.parent}px parent`,
      ).toBeLessThanOrEqual(measured!.parent! + 1);

      // And the page as a whole still never scrolls sideways.
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth),
        `${route} @${width}: horizontal page scroll`,
      ).toBeLessThanOrEqual(1);
    }
  });
}

test('the breadcrumb trail fits its slot at 768 instead of painting over search', async ({ page }) => {
  // F-019: the header's first grid column is 260px at 768. The company name's
  // 160px cap left the trail 16px, and the trail does not shrink on its own —
  // every crumb is whitespace-nowrap — so a 3-segment route painted ~37px over
  // the search control. Measured, then fixed by hiding the name below `lg` and
  // containing the trail.
  await page.setViewportSize({ width: 768, height: 1024 });
  await mockQualityLookups(page);
  await loginAs(page, 'qc', '/quality/ncr-templates/new');

  const trail = await page.evaluate(() => {
    const nav = document.querySelector('header nav[aria-label="Breadcrumb"]');
    const slot = nav?.parentElement;
    if (!nav || !slot) return null;
    const navBox = nav.getBoundingClientRect();
    const slotBox = slot.getBoundingClientRect();
    const search = document.querySelector('header button[class*="w-44"]');
    const searchBox = search?.getBoundingClientRect();
    return {
      navScroll: nav.scrollWidth,
      navClient: nav.clientWidth,
      navRight: Math.round(navBox.right),
      slotRight: Math.round(slotBox.right),
      searchLeft: searchBox ? Math.round(searchBox.left) : null,
      crumb: (nav.textContent ?? '').trim(),
    };
  });

  expect(trail, 'breadcrumb trail not found in the header').not.toBeNull();
  // The trail's content fits the box it is given — before the fix scrollWidth
  // was 201 inside a 16px clientWidth.
  expect(trail!.navScroll, `trail overflows its box (${trail!.crumb})`).toBeLessThanOrEqual(
    trail!.navClient + 1,
  );
  expect(trail!.navRight).toBeLessThanOrEqual(trail!.slotRight + 1);
  if (trail!.searchLeft !== null) {
    expect(trail!.navRight, 'trail overlaps the search control').toBeLessThanOrEqual(trail!.searchLeft);
  }
});
