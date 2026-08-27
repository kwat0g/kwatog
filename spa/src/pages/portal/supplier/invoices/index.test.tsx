import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SupplierInvoicesPage from './index';
import { supplierPortalApi } from '@/api/b2b/supplier';

vi.mock('@/api/b2b/supplier', () => ({
  supplierPortalApi: {
    listInvoices: vi.fn(),
  },
}));

function renderPage(): void {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/portal/supplier/invoices']}>
        <SupplierInvoicesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('supplier invoice status filter', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(supplierPortalApi.listInvoices).mockResolvedValue({
      data: [],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 0,
        from: null,
        to: null,
      },
      links: { first: '', last: '', prev: null, next: null },
    });
  });

  it('offers only statuses from the supplier-visible invoice contract', async () => {
    renderPage();

    const statusFilter = await screen.findByRole('combobox', { name: 'Status' });
    const options = within(statusFilter).getAllByRole('option');

    expect(options.map((option) => ({
      value: option.getAttribute('value'),
      label: option.textContent,
    }))).toEqual([
      { value: '', label: 'Status: All' },
      { value: 'unpaid', label: 'Unpaid' },
      { value: 'partial', label: 'Partially paid' },
      { value: 'paid', label: 'Paid' },
    ]);
    expect(screen.queryByRole('option', { name: 'Draft' })).not.toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Cancelled' })).not.toBeInTheDocument();
  });
});
