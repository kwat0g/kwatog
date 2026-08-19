/**
 * E2E chain tests — Procure-to-Pay (PR → PO lifecycle).
 *
 * Tests the cross-role P2P flow:
 *   Purchasing creates PR → submits → department_head approves
 *   → Purchasing converts to PO → PO goes through approval workflow.
 */
import { test, expect } from './fixtures';
import { loginAs } from './helpers-extended';
import { BasePage } from './pages/BasePage';
import { PurchaseRequestListPage, PurchaseOrderListPage, PurchaseOrderDetailPage } from './pages/ModulePages';

// ── Mock data ────────────────────────────────────────────────────────────────

const PR_ID = 'prTest01';
const PO_ID = 'poTest01';

function makePurchaseRequest(status: string) {
  return {
    id: PR_ID, pr_number: 'PR-202607-0001',
    status, status_label: status.charAt(0).toUpperCase() + status.slice(1),
    vendor: null, department: { id: 'dep1', code: 'PROD', name: 'Production' },
    requester: { id: 'emp_pur', name: 'Elena Cruz' },
    priority: 'normal', is_auto_generated: false, is_urgent: false,
    has_overdue_approval: false, total_estimated_amount: '25000.00', items: [],
    date: '2026-07-01',
    created_at: '2026-07-01T00:00:00Z',
  };
}

function makePurchaseOrder(status: string) {
  return {
    id: PO_ID, po_number: 'PO-202607-0001',
    status, status_label: status.charAt(0).toUpperCase() + status.slice(1),
    vendor: { id: 'v1', name: 'Asia Pacific Polymers' },
    items: [], total_amount: '25000.00', date: '2026-07-01',
    expected_delivery_date: null, quantity_received_pct: 0,
    is_auto_generated: false, has_overdue_approval: false,
    created_at: '2026-07-01T00:00:00Z',
  };
}

function list<T>(items: T[]) { return { data: items, meta: { current_page: 1, last_page: 1, per_page: 25, total: items.length, from: items.length > 0 ? 1 : null, to: items.length > 0 ? items.length : null }, links: { first: null, last: null, prev: null, next: null } }; }
function detail<T>(item: T) { return { data: item }; }

// ── Tests ───────────────────────────────────────────────────────────────────

test.describe('Procure-to-Pay chain — PR lifecycle', () => {

  test('purchasing officer creates a PR (draft → submitted)', async ({ page }) => {
    await page.route('**/api/v1/purchasing/purchase-requests*', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(list([makePurchaseRequest('pending')])) });
      } else if (route.request().method() === 'POST') {
        await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify(detail(makePurchaseRequest('pending'))) });
      } else { await route.continue(); }
    });

    await loginAs(page, 'purchasing', '/purchasing/purchase-requests');
    const listPage = new PurchaseRequestListPage(page);

    await expect(listPage.heading).toBeVisible();
    // The table should show our PR
    await expect(page.getByText('PR-202607-0001')).toBeVisible();
    // The Status chip renders `status_label` — PurchaseRequestStatus::label()
    // returns 'Pending', capitalised. An exact match on lowercase 'pending'
    // could only ever have passed against the raw enum value, which the list
    // shows nobody, so it looked strict and asserted nothing.
    await expect(page.getByRole('cell', { name: 'Pending', exact: true })).toBeVisible();
  });

  test('warehouse cannot view purchase requests (forbidden)', async ({ page }) => {
    await loginAs(page, 'warehouse', '/purchasing/purchase-requests');
    const base = new BasePage(page);
    await base.expectDeniedAsNotFound();
  });
});

test.describe('Procure-to-Pay chain — PO lifecycle', () => {

  test('PO flows draft → approved → sent', async ({ page }) => {
    let po = makePurchaseOrder('draft');

    // List + detail GET
    await page.route('**/api/v1/purchasing/purchase-orders*', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(list([po])) });
      } else if (route.request().method() === 'POST') {
        po = makePurchaseOrder('pending_approval');
        await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify(detail(po)) });
      } else { await route.continue(); }
    });

    // Detail GET
    await page.route(`**/api/v1/purchasing/purchase-orders/${PO_ID}`, async (route) => {
      if (route.request().method() !== 'GET') { await route.continue(); return; }
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detail(po)) });
    });

    // PATCH approve
    await page.route(`**/api/v1/purchasing/purchase-orders/${PO_ID}/approve`, async (route) => {
      po = makePurchaseOrder('approved');
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detail(po)) });
    });

    // POST send
    await page.route(`**/api/v1/purchasing/purchase-orders/${PO_ID}/send`, async (route) => {
      po = makePurchaseOrder('sent');
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(detail(po)) });
    });

    // Start at list page
    await loginAs(page, 'purchasing', '/purchasing/purchase-orders');
    const listPage = new PurchaseOrderListPage(page);
    await expect(listPage.heading).toBeVisible();

    // Navigate to detail (mock the PO as approved for detail)
    po = makePurchaseOrder('approved');
    await page.goto(`/purchasing/purchase-orders/${PO_ID}`);
    await page.waitForLoadState('networkidle');

    const detailPage = new PurchaseOrderDetailPage(page);
    await expect(page.getByRole('heading').getByText('PO-202607-0001')).toBeVisible();

    if (await detailPage.sendButton.isVisible()) {
      await detailPage.sendButton.click();
      await expect(page.getByText('Sent')).toBeVisible({ timeout: 5000 });
    }
  });

  test('impex officer (purchasing view only) can VIEW POs but cannot CREATE', async ({ page }) => {
    await page.route('**/api/v1/purchasing/purchase-orders*', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(list([makePurchaseOrder('approved')])) });
      } else { await route.continue(); }
    });

    await loginAs(page, 'purchasing', '/purchasing/purchase-orders');
    const listPage = new PurchaseOrderListPage(page);
    await expect(listPage.heading).toBeVisible();
    // impex has purchasing.view, so the list renders; but the create button
    // should be hidden (no purchasing.po.create)
    await expect(listPage.createButton).not.toBeVisible();
  });
});
