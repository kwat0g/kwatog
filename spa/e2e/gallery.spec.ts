/**
 * W1.2 — saved visual baselines for the shared component kit.
 *
 * WHAT THIS CATCHES THAT THE OTHER GATES CANNOT
 * `audit:tokens` proves a colour came from a token; `test:a11y` proves a control
 * has a name; `narrow-width.test.ts` proves a class string is present. None of
 * them can see that a primitive now renders 2px shorter, loses its focus ring,
 * or pushes its label outside the panel header — the F-009/F-010 class of defect,
 * found only because a real browser photographed the page. Until now that
 * photographing was a manual, one-off exercise (`scripts/ui-ux-baseline-capture.mjs`
 * against live routes with seeded data). Here it is 16 committed PNGs of the
 * gallery's sections, so a change to a shared component fails a check on its own
 * rather than waiting for someone to notice on whichever page happens to use it.
 *
 * WHY THE GALLERY AND NOT REAL ROUTES
 * A real page's screenshot moves when the data moves. The gallery renders
 * literals only (see its header), so the only thing that can change between two
 * runs is the code.
 *
 * WHY 390 AND 1280
 * The narrow width is where both open width findings lived; 1280 is the design
 * size the typography scale is specified at. A screenshot at only one of them
 * would pass while the other broke.
 *
 * UPDATING A BASELINE IS A REVIEW, NOT A FIX.
 *   npm run test:gallery:update
 * then open the changed PNGs and say, in the PR, why the pixels moved.
 */
import { test, expect, type Page } from './fixtures';
import { USERS, mockAuth, type MockUser } from './helpers';

/** `usePermission` short-circuits for system_admin, so nothing here measures a
 *  permission guard instead of a component. */
const GALLERY_USER: MockUser = {
  ...USERS.finance,
  id: 'gallery-review',
  name: 'Gallery Review',
  email: 'gallery@ogami.test',
  roleSlug: 'system_admin',
  roleName: 'System Administrator',
  permissions: [],
  employee: null,
};

/** Mirrors `BASELINE_SECTIONS` in `src/pages/admin/component-gallery/index.test.tsx`. */
const SECTIONS = [
  'buttons',
  'status',
  'forms',
  'feedback',
  'panels',
  'charts',
  'controls',
  'tables',
  'records',
] as const;

const VIEWPORTS = [
  { name: '1280', width: 1280, height: 900 },
  { name: '390', width: 390, height: 844 },
] as const;

/**
 * Every screenshot is compared against the committed file, with a tolerance
 * that absorbs antialiasing and nothing else.
 *
 * `maxDiffPixelRatio: 0.002` is 0.2% of the section — on a 1000×400 section
 * that is 800 pixels, enough for subpixel text rendering to differ between two
 * runs of the same code, and far too little to hide a moved box, a lost border
 * or a font-size step (each of those moves thousands).
 */
const SCREENSHOT = {
  animations: 'disabled',
  caret: 'hide',
  maxDiffPixelRatio: 0.002,
  threshold: 0.2,
} as const;

async function openGallery(page: Page, width: number, height: number) {
  await page.setViewportSize({ width, height });
  await mockAuth(page, GALLERY_USER);
  await page.goto('/admin/component-gallery', { waitUntil: 'load' });
  await expect(
    page.getByRole('heading', { level: 1, name: 'Component gallery' }),
  ).toBeVisible();
  /* Type metrics come from the bundled fonts; a screenshot taken before they
   * swap measures the fallback face and would drift on the next run. */
  await page.evaluate(async () => {
    await document.fonts.ready;
  });
  /* The topbar is `sticky top-0` and the sidebar is a sibling `aside`. An
   * element screenshot captures the page region at the element's box, so a
   * sticky bar would paint over the top of a scrolled-into-view section.
   * `visibility: hidden` (not `display: none`) keeps their layout footprint,
   * so the content width is exactly what a real user sees. */
  await page.addStyleTag({
    content: 'header.sticky, aside[aria-label="Sidebar"] { visibility: hidden !important; }',
  });
}

for (const viewport of VIEWPORTS) {
  for (const id of SECTIONS) {
    test(`gallery — ${id} @${viewport.name}`, async ({ page }) => {
      await openGallery(page, viewport.width, viewport.height);

      const section = page.locator(`[data-gallery-section="${id}"]`);
      await expect(section, `section ${id} is missing from the gallery`).toHaveCount(1);
      await section.scrollIntoViewIfNeeded();
      if (id === 'tables') await expect(section.locator('.detail-layout__aside')).toBeVisible();

      await expect(section).toHaveScreenshot(`${id}-${viewport.name}.png`, SCREENSHOT);
    });
  }
}

/**
 * Geometry, not just pixels — the same two numbers the F-009/F-010 captures
 * were measured with, now asserted on every run.
 *
 * A PNG comparison only catches a regression it has a baseline for. These two
 * checks catch the *class*: no section may paint outside its own box, and the
 * page must not become horizontally draggable at 390px. Elements that own their
 * overflow (`overflow-x-auto`, the Panel scrollBody contract) are exempt —
 * scrolling is the fix, not the defect.
 */
test('gallery — nothing overflows horizontally at 390', async ({ page }) => {
  await openGallery(page, 390, 844);

  const report = await page.evaluate(() => {
    /*
     * Two measurements, because they answer different questions.
     *
     * 1. `scrollWidth` of the section itself: does its content force the
     *    section wider than the column it was given? This is the F-010 shape.
     * 2. Right edge past the viewport: does anything PAINT off-screen? This is
     *    the F-009 shape. Measured on the bounding box rather than scrollWidth
     *    because scrollWidth is wrong for inline boxes (clientWidth is 0, so an
     *    inline span always looks overwide) and counts deliberately-overhanging
     *    decoration (the DatumMark behind an empty-state icon). An element inside
     *    a container that owns its overflow (`overflow-x: auto`, the Panel
     *    scrollBody contract) is exempt — scrolling is the fix, not the defect.
     */
    const pageWidth = document.documentElement.clientWidth;
    const sections: Array<{ id: string; over: number }> = [];
    const offscreen: Array<{ where: string; by: number }> = [];

    const clipped = (el: HTMLElement, section: HTMLElement) => {
      for (let p = el.parentElement; p && p !== section.parentElement; p = p.parentElement) {
        if (getComputedStyle(p).overflowX !== 'visible') return true;
      }
      return false;
    };

    for (const section of document.querySelectorAll<HTMLElement>('[data-gallery-section]')) {
      const id = section.dataset.gallerySection ?? '?';
      const over = section.scrollWidth - section.clientWidth;
      if (over > 1) sections.push({ id, over });

      for (const el of section.querySelectorAll<HTMLElement>('*')) {
        const style = getComputedStyle(el);
        if (style.display === 'none') continue;
        const rect = el.getBoundingClientRect();
        if (rect.width === 0 || rect.right <= pageWidth + 1) continue;
        if (clipped(el, section)) continue;
        offscreen.push({
          where: `${id}: ${el.tagName.toLowerCase()}.${[...el.classList].slice(0, 2).join('.')}`,
          by: Math.round(rect.right - pageWidth),
        });
      }
    }

    return {
      pageOverflow: document.documentElement.scrollWidth - pageWidth,
      sections,
      offscreen: offscreen.slice(0, 10),
    };
  });

  expect(report.sections, 'a section is wider than its column').toEqual([]);
  expect(report.offscreen, 'elements painting past the right edge').toEqual([]);
  expect(report.pageOverflow, 'the page is horizontally draggable').toBeLessThanOrEqual(0);
});

/**
 * W1.3 — the phone action surface, held at both widths.
 *
 * These two shots are the same dialog with the same props at the same scroll
 * position; the only difference is the viewport, so the pair is the evidence for
 * the rule itself. They are captured from the dialog's own box rather than from
 * a section, because the dialog is `position: fixed` and lives outside the
 * section it was opened from.
 */
for (const viewport of VIEWPORTS) {
  test(`gallery — dialog presentation @${viewport.name}`, async ({ page }) => {
    await openGallery(page, viewport.width, viewport.height);
    // Deterministic backdrop: the dialog is translucent at 60%, so whatever the
    // page is scrolled to shows through it.
    await page.evaluate(() => window.scrollTo(0, 0));

    await page.getByRole('button', { name: 'Open modal' }).click();

    const expected = viewport.name === '390' ? 'sheet' : 'dialog';
    const panel = page.locator(`[data-presentation="${expected}"]`);
    await expect(panel, `a dialog at ${viewport.name} should be a ${expected}`).toBeVisible();

    await expect(panel).toHaveScreenshot(`dialog-${viewport.name}.png`, SCREENSHOT);
  });
}

/**
 * The negative control, and the reason the loop above can be trusted.
 *
 * A visual gate whose comparison never runs — wrong template path, tolerance so
 * wide it accepts anything, a `toHaveScreenshot` on a locator that resolved to
 * an empty element — reports every shot as passing. This plants a real visual
 * change (a 40px indent on the buttons section) and requires the gate to see it.
 * If this test starts failing because the diff is gone, the gate is dead.
 */
test('gallery — the baseline can see a planted change (negative control)', async ({ page }) => {
  await openGallery(page, 1280, 900);
  const section = page.locator('[data-gallery-section="buttons"]');
  await section.scrollIntoViewIfNeeded();

  await page.addStyleTag({
    content: '[data-gallery-section="buttons"] { padding-left: 40px !important; }',
  });

  await expect(section).not.toHaveScreenshot('buttons-1280.png', {
    ...SCREENSHOT,
    timeout: 5_000,
  });
});

test('gallery — form grid follows the approved breakpoints', async ({ page }) => {
  await openGallery(page, 375, 844);

  for (const [width, expectedColumns] of [[375, 1], [768, 2], [1280, 2], [1440, 3], [1920, 4]] as const) {
    await page.setViewportSize({ width, height: 900 });
    const grid = page.locator('[data-layout="form-grid"][data-columns="4"]');
    const columns = await grid.evaluate((element) =>
      getComputedStyle(element).gridTemplateColumns.trim().split(/\s+/).length,
    );
    expect(columns, `FormGrid at ${width}px`).toBe(expectedColumns);
  }
});
