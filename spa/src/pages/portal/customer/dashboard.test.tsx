import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { customerPortalApi } from '@/api/b2b/customer';
import type { CustomerPortalUser } from '@/types/b2b';
import CustomerDashboardPage from './dashboard';

vi.mock('@/api/b2b/customer', () => ({
  customerPortalApi: { dashboard: vi.fn(), me: vi.fn() },
}));

const customerTerms = {
  allowed_payment_methods: ['check', 'bank_transfer'] as Array<'check' | 'bank_transfer'>,
  shipping_payer: 'company' as const,
  agreement_starts_on: '2026-11-11',
  agreement_ends_on: '2027-11-10',
  agreement_status: 'in_effect' as const,
  payment_terms_days: 30,
  warranty_defaults: {
    warranty_duration_days: 999,
    warranty_report_window_days: 999,
    warranty_start_basis: 'invoice' as const,
    warranty_covered_issues: ['manufacturing_defect'],
    warranty_remedies: ['refund'],
  },
} satisfies NonNullable<CustomerPortalUser['customer_terms']>;

function renderDashboard() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter><CustomerDashboardPage /></MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(customerPortalApi.dashboard).mockResolvedValue({
    open_so_count: 0,
    pending_delivery_count: 0,
    open_invoice_count: 0,
    total_outstanding: '0.00',
    recent_orders: [],
    recent_invoices: [],
    recent_deliveries: [],
    recent_complaints: [],
    product_terms: [
      {
        id: 'product-1', part_number: 'PC-001', name: 'Pivot Cap Cover Type A', unit_of_measure: 'pcs',
        minimum_order_quantity: '5.000', maximum_order_quantity: '250.000',
        warranty_options: [{
          id: 'warranty-1', label: '12-month standard', warranty_duration_days: 365,
          warranty_report_window_days: 30, warranty_start_basis: 'delivery',
          warranty_covered_issues: ['manufacturing_defect', 'shipping_damage'],
          warranty_remedies: ['repair', 'replace'], additional_terms: 'Covers listed factory defects.',
        }],
      },
      {
        id: 'product-2', part_number: 'PC-002', name: 'Pivot Cap Cover Type B', unit_of_measure: 'pcs',
        minimum_order_quantity: '1.000', maximum_order_quantity: null, warranty_options: [],
      },
    ],
  });
  vi.mocked(customerPortalApi.me).mockResolvedValue({
    id: 'portal-user', name: 'Buyer', email: 'buyer@example.test', customer_id: 'customer-1', customer_name: 'Honda',
    must_change_password: false, resends_remaining_today: 3, is_active: true, is_authorized_signatory: false,
    last_login_at: null, created_at: '2026-11-11', status: 'active', deleted_at: null,
    customer: { id: 'customer-1', name: 'Honda' }, customer_terms: customerTerms,
  });
});

afterEach(cleanup);

describe('customer portal account terms', () => {
  it('shows product-specific order limits and warranty terms from the agreement', async () => {
    renderDashboard();

    expect(await screen.findByText('12-month standard')).toBeInTheDocument();
    expect(screen.getByText('Account default payment terms')).toBeInTheDocument();
    expect(screen.getByText(/request different terms on each order/i)).toBeInTheDocument();
    expect(screen.getByText('PC-001 · Pivot Cap Cover Type A')).toBeInTheDocument();
    expect(screen.getByText('Quantity per order: 5–250 pcs')).toBeInTheDocument();
    expect(screen.getByText('365 days')).toBeInTheDocument();
    expect(screen.getByText('Report deadline (from delivery)')).toBeInTheDocument();
    expect(screen.getByText('Warranty starts from')).toBeInTheDocument();
    expect(screen.getAllByText('30 days')).toHaveLength(2);
    expect(screen.getByText('Delivery')).toBeInTheDocument();
    expect(screen.getByText('Manufacturing defect, Shipping damage')).toBeInTheDocument();
    expect(screen.getByText('Repair, Replace')).toBeInTheDocument();
    expect(screen.getByText('Covers listed factory defects.')).toBeInTheDocument();
    expect(screen.getByText('Quantity per order: At least 1 pcs (no maximum)')).toBeInTheDocument();
    expect(screen.queryByText('999 days')).not.toBeInTheDocument();
  });
});
