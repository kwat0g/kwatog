import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import toast from 'react-hot-toast';
import { supplierListingsApi } from '@/api/purchasing/supplier-listings';
import type { SupplierItemListing } from '@/types/purchasing';
import SupplierListingsReviewPage from './index';

vi.mock('@/api/purchasing/supplier-listings', () => ({
  supplierListingsApi: {
    list: vi.fn(),
    approve: vi.fn(),
    reject: vi.fn(),
    bulkApprove: vi.fn(),
    bulkReject: vi.fn(),
  },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ canApprove: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('react-hot-toast', () => ({ default: { error: vi.fn(), success: vi.fn() } }));

const listing = (overrides: Partial<SupplierItemListing> = {}): SupplierItemListing => ({
  id: 'listing-1',
  item: { id: 'item-1', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
  vendor: { id: 'vendor-1', name: 'Supplier One' },
  supplier_item_code: 'SUP-001',
  supplier_item_name: 'Supplier Resin',
  price: '100.00',
  order_uom: 'bag',
  base_qty_per_order_unit: '25.0000',
  lead_time_days: 5,
  valid_until: '2027-01-01',
  status: 'pending',
  rejection_reason: null,
  submitted_at: '2026-11-01T00:00:00Z',
  reviewed_at: null,
  reviewer: null,
  permissions: { view: true, review: true },
  available_actions: { approve: true, reject: true },
  ...overrides,
});

function page(data: SupplierItemListing[]) {
  return {
    data,
    links: { first: '', last: '', prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: data.length ? 1 : null, to: data.length || null },
  };
}

function renderPage(queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })) {
  return {
    queryClient,
    ...render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter><SupplierListingsReviewPage /></MemoryRouter>
      </QueryClientProvider>,
    ),
  };
}

describe('supplier listing realtime review actions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    vi.mocked(supplierListingsApi.list).mockResolvedValue(page([listing()]));
    vi.mocked(supplierListingsApi.approve).mockResolvedValue(listing({
      status: 'approved',
      reviewed_at: '2026-11-03T00:00:00Z',
      permissions: { view: true, review: true },
      available_actions: { approve: false, reject: false },
    }));
  });

  it('shows the supplier-provided product photo in the internal review list', async () => {
    vi.mocked(supplierListingsApi.list).mockResolvedValue(page([listing({
      image: {
        original_filename: 'resin-sample.jpg',
        mime_type: 'image/jpeg',
        size_bytes: 1234,
        url: '/api/v1/purchasing/supplier-listings/listing-1/image',
      },
    })]));

    renderPage();

    const photos = await screen.findAllByRole('img', { name: 'Resin supplier listing photo' });
    expect(photos.length).toBeGreaterThan(0);
    photos.forEach((photo) => {
      expect(photo).toHaveAttribute('src', '/api/v1/purchasing/supplier-listings/listing-1/image');
    });
  });

  it('hides status-derived controls when the server says this row is not actionable', async () => {
    vi.mocked(supplierListingsApi.list).mockResolvedValue(page([listing({
      available_actions: { approve: false, reject: false },
    })]));
    renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    expect(within(row).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('fails closed when server action fields are missing for row and bulk actions', async () => {
    const legacyListing = listing();
    delete legacyListing.available_actions;
    vi.mocked(supplierListingsApi.list).mockResolvedValue(page([legacyListing]));
    renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    expect(within(row).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();

    fireEvent.click(within(row).getByRole('checkbox', { name: 'Select row' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve selected' }));
    expect(toast.error).toHaveBeenCalledWith('Select at least one pending listing to approve.');
    expect(supplierListingsApi.bulkApprove).not.toHaveBeenCalled();
  });

  it('does not submit a bulk action for a pending row the server marks unavailable', async () => {
    vi.mocked(supplierListingsApi.list).mockResolvedValue(page([listing({
      available_actions: { approve: false, reject: false },
    })]));
    renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    fireEvent.click(within(row).getByRole('checkbox', { name: 'Select row' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve selected' }));

    expect(toast.error).toHaveBeenCalledWith('Select at least one pending listing to approve.');
    expect(supplierListingsApi.bulkApprove).not.toHaveBeenCalled();
  });

  it('shows the successful API response on the actor screen before list refetch finishes', async () => {
    let listCalls = 0;
    vi.mocked(supplierListingsApi.list).mockImplementation(() => {
      listCalls += 1;
      return listCalls === 1 ? Promise.resolve(page([listing()])) : new Promise(() => {});
    });
    renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    fireEvent.click(within(row).getByRole('button', { name: 'Approve' }));

    await waitFor(() => expect(supplierListingsApi.approve).toHaveBeenCalledWith('listing-1'));
    expect(await within(row).findByText('Approved', { exact: true })).toBeInTheDocument();
    await waitFor(() => expect(within(row).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument());
    expect(within(row).queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('disables a rejection confirmation while the authorized listing refetch is pending', async () => {
    const stale = listing();
    vi.mocked(supplierListingsApi.list).mockResolvedValueOnce(page([stale]));
    let resolveRefresh!: (response: Awaited<ReturnType<typeof supplierListingsApi.list>>) => void;
    vi.mocked(supplierListingsApi.list).mockReturnValueOnce(new Promise((resolve) => {
      resolveRefresh = resolve;
    }));
    const { queryClient } = renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    fireEvent.click(within(row).getByRole('button', { name: 'Reject' }));
    const dialog = screen.getByRole('dialog', { name: 'Reject listing' });
    fireEvent.change(within(dialog).getByLabelText('Reason for rejection'), {
      target: { value: 'Offer has an invalid price basis' },
    });
    const listQuery = queryClient.getQueryCache().findAll().find(
      (query) => query.queryKey[0] === 'purchasing' && query.queryKey[1] === 'supplier-listings',
    );
    expect(listQuery).toBeDefined();

    act(() => {
      void queryClient.invalidateQueries({ queryKey: listQuery!.queryKey, exact: true });
    });
    await waitFor(() => expect(supplierListingsApi.list).toHaveBeenCalledTimes(2));
    expect(within(dialog).getByRole('button', { name: 'Reject listing' })).toBeDisabled();

    await act(async () => {
      resolveRefresh(page([listing({ available_actions: { approve: false, reject: false } })]));
    });
    await waitFor(() => expect(dialog).toHaveTextContent(/no longer available for rejection/i));
    expect(within(dialog).getByLabelText('Reason for rejection')).toHaveValue('Offer has an invalid price basis');
    expect(within(dialog).getByRole('button', { name: 'Reject listing' })).toBeDisabled();
    expect(supplierListingsApi.reject).not.toHaveBeenCalled();

    queryClient.clear();
  });

  it('disables row approval while the supplier-listing query is refreshing', async () => {
    vi.mocked(supplierListingsApi.list).mockResolvedValueOnce(page([listing()]));
    vi.mocked(supplierListingsApi.list).mockReturnValueOnce(new Promise(() => {}));
    const { queryClient } = renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    const listQuery = queryClient.getQueryCache().findAll().find(
      (query) => query.queryKey[0] === 'purchasing' && query.queryKey[1] === 'supplier-listings',
    );
    expect(listQuery).toBeDefined();

    act(() => {
      void queryClient.invalidateQueries({ queryKey: listQuery!.queryKey, exact: true });
    });
    await waitFor(() => expect(supplierListingsApi.list).toHaveBeenCalledTimes(2));
    expect(within(row).getByRole('button', { name: 'Approve' })).toBeDisabled();
    expect(supplierListingsApi.approve).not.toHaveBeenCalled();

    queryClient.clear();
  });

  it('disables bulk supplier-listing actions while the list is refreshing', async () => {
    vi.mocked(supplierListingsApi.list).mockResolvedValueOnce(page([listing()]));
    vi.mocked(supplierListingsApi.list).mockReturnValueOnce(new Promise(() => {}));
    const { queryClient } = renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    fireEvent.click(within(row).getByRole('checkbox', { name: 'Select row' }));
    const approveButton = await screen.findByRole('button', { name: 'Approve selected' });
    const rejectButton = screen.getByRole('button', { name: 'Reject selected' });
    const listQuery = queryClient.getQueryCache().findAll().find(
      (query) => query.queryKey[0] === 'purchasing' && query.queryKey[1] === 'supplier-listings',
    );
    expect(listQuery).toBeDefined();

    act(() => {
      void queryClient.invalidateQueries({ queryKey: listQuery!.queryKey, exact: true });
    });
    await waitFor(() => expect(supplierListingsApi.list).toHaveBeenCalledTimes(2));
    expect(approveButton).toBeDisabled();
    expect(rejectButton).toBeDisabled();

    queryClient.clear();
  });

  it('revalidates selected listing IDs before bulk rejection', async () => {
    const stale = listing();
    vi.mocked(supplierListingsApi.list).mockResolvedValueOnce(page([stale]));
    let resolveRefresh!: (response: Awaited<ReturnType<typeof supplierListingsApi.list>>) => void;
    vi.mocked(supplierListingsApi.list).mockReturnValueOnce(new Promise((resolve) => {
      resolveRefresh = resolve;
    }));
    const { queryClient } = renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    fireEvent.click(within(row).getByRole('checkbox', { name: 'Select row' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Reject selected' }));
    const dialog = screen.getByRole('dialog', { name: 'Reject 1 listing(s)' });
    fireEvent.change(within(dialog).getByLabelText('Reason for rejection'), {
      target: { value: 'Offer has an invalid price basis' },
    });
    const listQuery = queryClient.getQueryCache().findAll().find(
      (query) => query.queryKey[0] === 'purchasing' && query.queryKey[1] === 'supplier-listings',
    );
    expect(listQuery).toBeDefined();

    act(() => {
      void queryClient.invalidateQueries({ queryKey: listQuery!.queryKey, exact: true });
    });
    await waitFor(() => expect(supplierListingsApi.list).toHaveBeenCalledTimes(2));
    expect(within(dialog).getByRole('button', { name: 'Reject selected' })).toBeDisabled();

    await act(async () => {
      resolveRefresh(page([listing({ available_actions: { approve: false, reject: false } })]));
    });
    await waitFor(() => expect(dialog).toHaveTextContent(/one or more selected listings are no longer available/i));
    expect(supplierListingsApi.bulkReject).not.toHaveBeenCalled();

    queryClient.clear();
  });

  it('reconciles successful bulk outcomes into the actor list before refetch finishes', async () => {
    let listCalls = 0;
    vi.mocked(supplierListingsApi.list).mockImplementation(() => {
      listCalls += 1;
      return listCalls === 1 ? Promise.resolve(page([listing()])) : new Promise(() => {});
    });
    vi.mocked(supplierListingsApi.bulkApprove).mockResolvedValue([
      { id: 'listing-1', status: 'approved', message: null },
    ]);
    renderPage();

    const row = await screen.findByRole('row', { name: /RM-001/ });
    fireEvent.click(within(row).getByRole('checkbox', { name: 'Select row' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Approve selected' }));

    await waitFor(() => expect(supplierListingsApi.bulkApprove).toHaveBeenCalledWith(['listing-1']));
    expect(await within(row).findByText('Approved', { exact: true })).toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(within(row).queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });
});
