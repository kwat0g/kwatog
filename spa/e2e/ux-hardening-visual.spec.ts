/**
 * Visual regression guard for the frontend UX-hardening branch.
 *
 * Everything here checks something that is invisible to unit tests and to
 * typecheck: whether a layout actually fits, whether a sticky bar actually
 * sticks, whether a trail actually sheds crumbs. The branch rewrote 133 grid
 * declarations across 78 files and put a sticky action row on 46 forms — those
 * are exactly the changes that pass every automated check and still look wrong.
 *
 * The hard assertion throughout is horizontal overflow: a document wider than
 * its viewport at 375px is the failure mode a hardcoded `grid-cols-4` produces.
 */
import { test, expect, type Page } from '@playwright/test';
import { loginAs, mockList } from './helpers-extended';

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

/** True when the document scrolls sideways — the tell for a too-rigid grid. */
async function overflowsHorizontally(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const doc = document.documentElement;
    // 1px of slack: sub-pixel rounding on borders is not an overflow.
    return doc.scrollWidth > doc.clientWidth + 1;
  });
}

/** Elements that stick out past the right edge of the viewport, with context. */
async function offscreenElements(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const width = document.documentElement.clientWidth;
    const out: string[] = [];
    document.querySelectorAll<HTMLElement>('main *').forEach((el) => {
      const box = el.getBoundingClientRect();
      if (box.width === 0 || box.height === 0) return;
      if (box.right > width + 1) {
        // A container that scrolls or clips is allowed to hold wide content —
        // only content that pushes the *document* wider is a layout bug.
        let parent = el.parentElement;
        while (parent) {
          const overflow = getComputedStyle(parent).overflowX;
          if (overflow === 'auto' || overflow === 'scroll' || overflow === 'hidden' || overflow === 'clip') return;
          parent = parent.parentElement;
        }
        out.push(`${el.tagName.toLowerCase()}.${el.className.toString().slice(0, 70)}`);
      }
    });
    return [...new Set(out)].slice(0, 8);
  });
}

test.describe('responsive grids', () => {
  test('an invoice detail page fits a 375px viewport', async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.route('**/api/v1/accounting/invoices/*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: 'inv1', invoice_number: 'INV-202604-0008', status: 'sent',
            status_label: 'Sent', customer: { id: 'c1', name: 'Toyota Motor Philippines' },
            invoice_date: '2026-04-01', due_date: '2026-05-01',
            subtotal: '125000.00', vat_amount: '15000.00', total: '140000.00',
            amount_paid: '0.00', balance: '140000.00', items: [], payments: [],
            currency_code: 'PHP', notes: null,
          },
        }),
      });
    });
    await loginAs(page, 'finance', '/accounting/invoices/inv1');
    await page.waitForTimeout(400);

    expect(await offscreenElements(page)).toEqual([]);
    expect(await overflowsHorizontally(page)).toBe(false);
  });

  test('a 12-column form row collapses instead of overflowing', async ({ page }) => {
    await page.setViewportSize(PHONE);
    mockList(page, '**/api/v1/accounting/accounts*', []);
    await loginAs(page, 'finance', '/accounting/journal-entries/create');
    await page.waitForTimeout(400);

    expect(await offscreenElements(page)).toEqual([]);
    expect(await overflowsHorizontally(page)).toBe(false);
  });

  test('the same page uses its columns on desktop', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    mockList(page, '**/api/v1/accounting/accounts*', []);
    await loginAs(page, 'finance', '/accounting/journal-entries/create');
    await page.waitForTimeout(400);

    // Collapsing on a phone must not mean collapsing everywhere: the grid has
    // to still be a grid at 1440px, or the fix traded one bug for another.
    const columns = await page
      .locator('.grid')
      .first()
      .evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
    expect(columns).toBeGreaterThan(1);
  });
});

test.describe('sticky form actions', () => {
  test('the submit button stays on screen while a long form scrolls', async ({ page }) => {
    await page.setViewportSize(PHONE);
    mockList(page, '**/api/v1/purchasing/purchase-requests*', []);
    mockList(page, '**/api/v1/inventory/items*', []);
    mockList(page, '**/api/v1/hr/departments*', []);
    await loginAs(page, 'purchasing', '/purchasing/purchase-requests/create');
    await page.waitForTimeout(400);

    const submit = page.locator('button[type="submit"]').first();
    await expect(submit).toBeVisible();

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(250);

    // Sticky means still in the viewport after scrolling to the bottom — the
    // whole point, since the row used to sit below several screens of inputs.
    await expect(submit).toBeInViewport();
    expect(await overflowsHorizontally(page)).toBe(false);
  });

  test('it returns to normal flow on desktop', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    mockList(page, '**/api/v1/purchasing/purchase-requests*', []);
    mockList(page, '**/api/v1/inventory/items*', []);
    await loginAs(page, 'purchasing', '/purchasing/purchase-requests/create');
    await page.waitForTimeout(400);

    const row = page.locator('button[type="submit"]').first().locator('xpath=ancestor::div[contains(@class,"sticky")]');
    // `md:static` — a bar pinned over a desktop form is just lost vertical space.
    if (await row.count() > 0) {
      expect(await row.first().evaluate((el) => getComputedStyle(el).position)).toBe('static');
    }
  });
});

test.describe('breadcrumbs', () => {
  test('exactly one trail renders, and it names the record', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.route('**/api/v1/purchasing/purchase-orders/*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          // Mirrors every non-nullable field of the PurchaseOrder type. An
          // incomplete fixture here does not "mostly work" — it throws inside
          // render, which is how this spec first caught the missing error
          // boundary in AppLayout.
          data: {
            id: 'nB4kQ2', po_number: 'PO-202604-0015', status: 'sent', status_label: 'Sent',
            date: '2026-04-01', expected_delivery_date: '2026-04-15',
            subtotal: '50000.00', vat_amount: '6000.00', total_amount: '56000.00',
            is_vatable: true, requires_vp_approval: false, is_auto_generated: false,
            has_overdue_approval: false, current_approval_step: 0,
            approved_at: null, sent_to_supplier_at: null, remarks: null,
            quantity_received_pct: 0, quantity_accepted_pct: 0,
            vendor: { id: 'v1', name: 'Resin Supplier Inc', contact_person: null, email: null },
            purchase_request: null, items: [], currency_code: 'PHP',
          },
        }),
      });
    });
    await loginAs(page, 'purchasing', '/purchasing/purchase-orders/nB4kQ2');
    await page.waitForTimeout(400);

    // Two trails on screen was the bug; PageHeader used to render a second one.
    await expect(page.getByRole('navigation', { name: 'Breadcrumb' })).toHaveCount(1);

    const trail = page.getByRole('navigation', { name: 'Breadcrumb' });
    // The hash id must never appear as a crumb label.
    await expect(trail).not.toContainText('nB4kQ2');
    await expect(trail).toContainText('PO-202604-0015');
    // And the module label comes from MODULE_LABELS, not a page-local string.
    await expect(trail).toContainText('Procurement');
  });

  test('the trail sheds ancestors rather than disappearing on a phone', async ({ page }) => {
    await page.setViewportSize(PHONE);
    mockList(page, '**/api/v1/hr/employees*', []);
    await loginAs(page, 'hr', '/hr/employees');
    await page.waitForTimeout(400);

    // It was `hidden md:flex`, so below 768px there was no wayfinding at all.
    const current = page.getByRole('navigation', { name: 'Breadcrumb' }).getByText('Employees');
    await expect(current).toBeVisible();
    expect(await overflowsHorizontally(page)).toBe(false);
  });
});

test.describe('pagination', () => {
  test('the rows-per-page control renders next to the pager', async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.route('**/api/v1/hr/employees*', async (route) => {
      if (route.request().method() !== 'GET') return route.continue();
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [{
            id: 'e1', employee_no: 'OGM-2026-0142', first_name: 'Ana', last_name: 'Reyes',
            full_name: 'Ana Reyes', status: 'active', status_label: 'Active',
            department: { id: 'd1', name: 'Production', code: 'PROD' },
            position: { id: 'p1', title: 'Operator' },
            date_hired: '2026-01-05', employment_type: 'regular',
          }],
          meta: { current_page: 1, last_page: 4, per_page: 25, total: 91, from: 1, to: 25 },
        }),
      });
    });
    await loginAs(page, 'hr', '/hr/employees');
    await page.waitForTimeout(400);

    await expect(page.getByLabel(/rows/i).first()).toBeVisible();
  });
});

test.describe('column-selectable export', () => {
  test('the employees list offers a column-selectable export', async ({ page }) => {
    await page.route('**/api/v1/hr/employees*', async (r) => {
      if (r.request().method() !== 'GET') return r.continue();
      await r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: 0, to: 0 } }) });
    });
    await page.route('**/api/v1/exports/hr.employees/columns*', async (r) => {
      await r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        data: { module: 'hr.employees', selected: ['employee_no'], columns: [
          { key: 'employee_no', label: 'Employee no', default: true, format: 'text' },
          { key: 'full_name', label: 'Full name', default: true, format: 'text' },
        ] } }) });
    });
    await loginAs(page, 'admin', '/hr/employees');
    const btn = page.getByRole('button', { name: 'Export' });
    await expect(btn).toBeVisible();
    await btn.click();
    // The modal must actually reach the columns endpoint and render choices.
    await expect(page.getByText('Employee no')).toBeVisible();
    await expect(page.getByText('Full name')).toBeVisible();
  });
});
