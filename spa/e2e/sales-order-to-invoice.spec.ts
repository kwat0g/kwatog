/**
 * E2E test: Order-to-Cash golden path
 *
 * Covers:
 *   1. Sales orders list — draft SO visible with correct status chip
 *   2. Confirm a sales order — POST confirm, status changes to confirmed
 *   3. Invoices list — empty state, New Invoice button visible
 *   4. Finalize an invoice — PATCH finalize, status chip shows finalized
 *
 * All API calls are intercepted via page.route() — no backend required.
 */
import { test, expect } from '@playwright/test';
import { mockAuth, type MockUser } from './helpers';

// ── User fixtures ────────────────────────────────────────────────────────────

const salesUser: MockUser = {
  id: 'u10',
  name: 'Sales Manager',
  email: 'sales@ogami.test',
  roleSlug: 'sales_manager',
  roleName: 'Sales Manager',
  permissions: [
    'crm.sales_orders.view',
    'crm.sales_orders.update',
    'crm.sales_orders.confirm',
  ],
  employee: null,
};

const financeUser: MockUser = {
  id: 'u11',
  name: 'Finance Officer',
  email: 'finance@ogami.test',
  roleSlug: 'finance_officer',
  roleName: 'Finance Officer',
  permissions: [
    'accounting.invoices.view',
    'accounting.invoices.create',
    'accounting.invoices.update',
  ],
  employee: null,
};

// ── Shared mock data ──────────────────────────────────────────────────────────

const SO_ID = 'xK9mP2';

const draftSO = {
  id: SO_ID,
  so_number: 'SO-202606-0001',
  status: 'draft',
  status_label: 'Draft',
  is_editable: true,
  is_cancellable: false,
  customer: { id: 'cAb3Zq', name: 'Toyota Motor Philippines' },
  date: '2026-06-04',
  delivery_date: '2026-06-20',
  total_amount: '150000.00',
  lines: [],
  mrp_plan: null,
};

const INV_ID = 'mQ7rN5';

const draftInvoice = {
  id: INV_ID,
  invoice_number: 'INV-202606-0001',
  status: 'draft',
  display_status: 'Draft',
  amount_paid: '0.00',
  balance: '150000.00',
  date: '2026-06-04',
  due_date: '2026-07-04',
  customer: { id: 'cAb3Zq', name: 'Toyota Motor Philippines' },
  journal_entry: null,
  payments: [],
};

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe('Order-to-Cash golden path', () => {

  test('renders sales orders list', async ({ page }) => {
    await mockAuth(page, salesUser);

    await page.route('**/api/v1/crm/sales-orders*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [draftSO],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
        }),
      });
    });

    await page.goto('/crm/sales-orders');
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('SO-202606-0001')).toBeVisible();
    await expect(page.getByText('Draft').first()).toBeVisible();
  });

  test('confirms a sales order', async ({ page }) => {
    await mockAuth(page, salesUser);

    await page.route(`**/api/v1/crm/sales-orders/${SO_ID}`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: draftSO }),
      });
    });

    await page.route(`**/api/v1/crm/sales-orders/${SO_ID}/confirm`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: { ...draftSO, status: 'confirmed', status_label: 'Confirmed', is_editable: false },
          chain_result: {},
        }),
      });
    });

    // Re-mock the detail GET to return confirmed state after mutation invalidates
    let confirmed = false;
    await page.route(`**/api/v1/crm/sales-orders/${SO_ID}**`, async (route) => {
      const so = confirmed
        ? { ...draftSO, status: 'confirmed', status_label: 'Confirmed', is_editable: false }
        : draftSO;
      confirmed = true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: so }),
      });
    });

    await page.goto(`/crm/sales-orders/${SO_ID}`);
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('SO-202606-0001')).toBeVisible();
    await page.getByRole('button', { name: /confirm order/i }).click();

    // Confirm dialog — click the confirm action
    await page.getByRole('button', { name: /confirm/i }).last().click();
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('Confirmed').first()).toBeVisible();
  });

  test('creates an invoice — shows list and New Invoice button', async ({ page }) => {
    await mockAuth(page, financeUser);

    await page.route('**/api/v1/invoices*', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [],
            meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
          }),
        });
      } else {
        // POST — return a newly created draft invoice
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({ data: draftInvoice }),
        });
      }
    });

    await page.goto('/accounting/invoices');
    await page.waitForLoadState('networkidle');

    await expect(page.getByRole('button', { name: /new invoice/i })).toBeVisible();
  });

  test('finalizes an invoice', async ({ page }) => {
    await mockAuth(page, financeUser);

    await page.route(`**/api/v1/invoices/${INV_ID}`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: draftInvoice }),
      });
    });

    await page.route(`**/api/v1/invoices/${INV_ID}/finalize`, async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: { ...draftInvoice, status: 'finalized', display_status: 'Finalized' },
        }),
      });
    });

    await page.goto(`/accounting/invoices/${INV_ID}`);
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('INV-202606-0001')).toBeVisible();
    await page.getByRole('button', { name: /finalize/i }).click();
    await page.waitForLoadState('networkidle');

    await expect(page.getByText('Finalized').first()).toBeVisible();
  });

});
