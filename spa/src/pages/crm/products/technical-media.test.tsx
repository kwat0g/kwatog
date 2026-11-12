import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Product } from '@/types/crm';
import { productsApi } from '@/api/crm/products';
import { ProductTechnicalMedia } from './technical-media';

vi.mock('@/api/crm/products', () => ({ productsApi: {
  uploadImages: vi.fn(), setPrimaryImage: vi.fn(), deleteImage: vi.fn(), uploadBlueprint: vi.fn(), deleteBlueprint: vi.fn(),
} }));

const product: Product = {
  id: 'product-1', part_number: 'P-001', name: 'Mounting Plate', description: null,
  unit_of_measure: 'pc', standard_cost: '10.00', base_selling_price: '20.00', minimum_selling_price: null,
  image_url: '/primary.jpg', images: [
    { id: 'img-front', url: '/front.jpg', position: 0, alt_text: 'Front face', is_primary: true },
    { id: 'img-side', url: '/side.jpg', position: 1, alt_text: 'Side profile', is_primary: false },
  ],
  blueprints: [], is_customizable: true, portal_visibility: 'restricted', portal_customers: [],
  revenue_account_id: null, is_active: true, include_forecast_in_mrp: false, has_bom: false,
  created_at: '2026-11-02T08:00:00Z', updated_at: '2026-11-02T08:00:00Z',
};

function open() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={queryClient}><ProductTechnicalMedia product={product} canManage /></QueryClientProvider>);
}

describe('ProductTechnicalMedia', () => {
  it('shows the ordered photo gallery and can change its primary image', async () => {
    vi.mocked(productsApi.setPrimaryImage).mockResolvedValue({});
    open();

    expect(screen.getByAltText('Front face')).toBeTruthy();
    expect(screen.getByAltText('Side profile')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Set Side profile as primary' }));

    await waitFor(() => expect(productsApi.setPrimaryImage).toHaveBeenCalledWith('product-1', 'img-side'));
  });

  it('uploads multiple product photos and a labeled blueprint revision', async () => {
    vi.mocked(productsApi.uploadImages).mockResolvedValue({});
    vi.mocked(productsApi.uploadBlueprint).mockResolvedValue({
      id: 'bp-1', title: 'Mounting plate drawing', revision: 'B', notes: null,
      filename: 'plate-b.pdf', mime: 'application/pdf', size: 120,
    });
    open();
    const photos = [
      new File(['front'], 'front.png', { type: 'image/png' }),
      new File(['side'], 'side.png', { type: 'image/png' }),
    ];
    fireEvent.change(screen.getByLabelText('Add product photos'), { target: { files: photos } });

    await waitFor(() => expect(productsApi.uploadImages).toHaveBeenCalledWith('product-1', photos));
    fireEvent.change(screen.getByLabelText('Blueprint file'), {
      target: { files: [new File(['%PDF-1.4'], 'plate-b.pdf', { type: 'application/pdf' })] },
    });
    const titleField = screen.getByRole('textbox', { name: /Blueprint title/ });
    const revisionField = screen.getByRole('textbox', { name: 'Revision' });
    fireEvent.change(titleField, { target: { value: 'Mounting plate drawing' } });
    fireEvent.change(revisionField, { target: { value: 'B' } });
    fireEvent.submit(titleField.closest('form')!);

    await waitFor(() => expect(productsApi.uploadBlueprint).toHaveBeenCalledWith('product-1', expect.objectContaining({
      title: 'Mounting plate drawing', revision: 'B', file: expect.any(File),
    })));
  });
});
