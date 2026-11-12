import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Outlet, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { supplierPortalApi } from '@/api/b2b/supplier';
import SupplierItemListingsPage from './item-listings';

vi.mock('@/api/b2b/supplier', () => ({ supplierPortalApi: {
  listItemListings: vi.fn(), itemCatalog: vi.fn(), createItemListing: vi.fn(), updateItemListing: vi.fn(),
  bulkCreateItemListings: vi.fn(), uploadItemListingImage: vi.fn(),
} }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useSupplierListingRealtime: vi.fn() }));

const portalUser = { id: 'supplier-user', name: 'Supplier User', vendor: { id: 'vendor-1', name: 'Supplier' } } as never;

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}><MemoryRouter initialEntries={['/portal/supplier/item-listings']}>
    <Routes><Route element={<Outlet context={portalUser} />}><Route path="/portal/supplier/item-listings" element={<SupplierItemListingsPage />} /></Route></Routes>
  </MemoryRouter></QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(supplierPortalApi.listItemListings).mockResolvedValue({
    data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(supplierPortalApi.itemCatalog).mockResolvedValue({
    data: [{ id: 'item-1', code: 'RM-1', name: 'Resin', item_type: 'raw_material', unit_of_measure: 'kg' }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(supplierPortalApi.createItemListing).mockResolvedValue({ id: 'listing-1' } as never);
  vi.mocked(supplierPortalApi.uploadItemListingImage).mockResolvedValue({ id: 'listing-1' } as never);
});

it('uploads an image selected for a supplier listing', async () => {
  const { container } = renderPage();
  fireEvent.click(await screen.findByRole('button', { name: 'New listing' }));
  await waitFor(() => expect(container.querySelector('select option[value="item-1"]')).not.toBeNull());
  const itemSelect = [...container.querySelectorAll('select')].find((select) =>
    [...select.options].some((option) => option.value === 'item-1'),
  );
  expect(itemSelect).toBeDefined();
  fireEvent.change(itemSelect!, { target: { value: 'item-1' } });
  fireEvent.change(screen.getByRole('spinbutton', { name: /Price/ }), { target: { value: '12.50' } });
  fireEvent.change(screen.getByRole('spinbutton', { name: /Lead time/ }), { target: { value: '3' } });
  const image = new File(['supplier product image'], 'resin.png', { type: 'image/png' });
  fireEvent.change(screen.getByLabelText('Listing image (optional)'), { target: { files: [image] } });
  fireEvent.click(screen.getByRole('button', { name: 'Submit for review' }));

  await waitFor(() => expect(supplierPortalApi.createItemListing).toHaveBeenCalledOnce());
  await waitFor(() => expect(supplierPortalApi.uploadItemListingImage).toHaveBeenCalledWith('listing-1', image));
  expect(supplierPortalApi.createItemListing).toHaveBeenCalledWith(expect.objectContaining({ item_id: 'item-1', price: '12.50' }));
});
