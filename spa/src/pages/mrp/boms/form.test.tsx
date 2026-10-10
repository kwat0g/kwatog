import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { itemsApi } from '@/api/inventory/items';
import { productsApi } from '@/api/crm/products';
import { bomsApi } from '@/api/mrp/boms';
import { uomsApi } from '@/api/inventory/uoms';
import CreateBomPage from './create';
import EditBomPage from './edit';

vi.mock('@/api/inventory/items', () => ({ itemsApi: { catalog: vi.fn() } }));
vi.mock('@/api/crm/products', () => ({
  productsApi: { list: vi.fn(), options: vi.fn(), show: vi.fn() },
}));
vi.mock('@/api/mrp/boms', () => ({ bomsApi: { create: vi.fn(), show: vi.fn(), update: vi.fn() } }));
vi.mock('@/api/inventory/uoms', () => ({ uomsApi: { list: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({ useFormSafety: () => ({}) }));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
const page = <T,>(data: T[]) => ({
  data,
  meta: {
    total: data.length,
    current_page: 1,
    last_page: 1,
    per_page: 100,
    from: 1,
    to: data.length,
  },
  links: { first: '', last: '', prev: null, next: null },
});
const itemA = { id: 'item-a', code: 'RESIN-A', name: 'Resin', unit_of_measure: 'KG' };
const itemB = { id: 'item-b', code: 'INSERT-B', name: 'Insert', unit_of_measure: 'PCS' };
function renderForm(mode: 'create' | 'edit' = 'create') {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter initialEntries={[mode === 'edit' ? '/mrp/boms/bom-1/edit' : '/']}>
        <Routes>
          <Route path="/" element={<CreateBomPage />} />
          <Route path="/mrp/boms/:id/edit" element={<EditBomPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(itemsApi.catalog).mockResolvedValue(page([itemA, itemB]));
  const product = {
    id: 'product-1',
    part_number: 'FG-1',
    name: 'Finished product',
    unit_of_measure: 'PCS',
  };
  vi.mocked(productsApi.options).mockResolvedValue(page([product]));
  vi.mocked(productsApi.list).mockResolvedValue(page([product as never]));
  vi.mocked(productsApi.show).mockResolvedValue({ ...product, has_bom: false } as never);
  vi.mocked(uomsApi.list).mockResolvedValue([
    { id: 'kg', code: 'KG', name: 'Kilogram', deleted_at: null },
    { id: 'pcs', code: 'PCS', name: 'Pieces', deleted_at: null },
  ]);
});
afterEach(cleanup);
describe('BOM form', () => {
  it('explains active-version replacement when the selected product already has a BOM', async () => {
    vi.mocked(productsApi.show).mockResolvedValue({
      id: 'product-1',
      part_number: 'FG-1',
      name: 'Finished product',
      unit_of_measure: 'PCS',
      active_bom: { id: 'bom-2', version: 2 },
    } as never);
    renderForm();
    const product = await screen.findByRole('combobox', { name: /^Finished product/ });
    fireEvent.focus(product);
    fireEvent.click(await screen.findByRole('option', { name: /FG-1/ }));
    expect(await screen.findByText(/Saving replaces active version 2/)).toHaveTextContent(
      'Existing Work Orders keep their assigned version; new Work Orders use the new version.',
    );
  });
  it('updates base unit when component changes and rejects invalid waste before submission', async () => {
    renderForm();
    const component = await screen.findByRole('combobox', { name: 'Component for line 1' });
    fireEvent.focus(screen.getByRole('combobox', { name: /^Finished product/ }));
    fireEvent.click(await screen.findByRole('option', { name: /FG-1/ }));
    fireEvent.focus(component);
    fireEvent.click(await screen.findByRole('option', { name: /RESIN-A/ }));
    expect(screen.getByRole('textbox', { name: 'Unit for line 1' })).toHaveValue('KG');
    expect(screen.getByRole('textbox', { name: 'Unit for line 1' })).toHaveAttribute('readonly');
    fireEvent.focus(component);
    fireEvent.change(component, { target: { value: 'INSERT' } });
    fireEvent.keyDown(component, { key: 'ArrowDown' });
    fireEvent.keyDown(component, { key: 'Enter' });
    expect(screen.getByRole('textbox', { name: 'Unit for line 1' })).toHaveValue('PCS');
    fireEvent.change(screen.getByLabelText('Quantity per unit for line 1'), {
      target: { value: '2' },
    });
    fireEvent.change(screen.getByLabelText('Waste percentage for line 1'), {
      target: { value: '51' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save BOM' }));
    expect(await screen.findByText('Waste must be between 0 and 50%.')).toBeVisible();
    expect(bomsApi.create).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText('Waste percentage for line 1'), {
      target: { value: '10' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save BOM' }));
    await waitFor(() =>
      expect(bomsApi.create).toHaveBeenCalledWith({
        product_id: 'product-1',
        cost_batch_size: '1',
        items: [
          {
            item_id: 'item-b',
            quantity_per_unit: '2',
            unit: 'PCS',
            waste_factor: '10',
            sort_order: 0,
          },
        ],
      }),
    );
  });
  it('locks the saved unit on Edit and includes it when saving a new version', async () => {
    vi.mocked(bomsApi.show).mockResolvedValue({
      id: 'bom-1',
      version: 1,
      cost_batch_size: '1',
      product: {
        id: 'product-1',
        part_number: 'FG-1',
        name: 'Finished product',
        unit_of_measure: 'PCS',
      },
      items: [{ item: itemA, quantity_per_unit: '2', unit: 'G', waste_factor: '0' }],
    } as never);
    renderForm('edit');
    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: 'Unit for line 1' })).toHaveValue('G'),
    );
    const unit = screen.getByRole('textbox', { name: 'Unit for line 1' });
    expect(unit).toHaveAttribute('readonly');
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Save new version' })).toBeEnabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save new version' }));
    await waitFor(() =>
      expect(bomsApi.update).toHaveBeenCalledWith('bom-1', {
        product_id: 'product-1',
        cost_batch_size: '1',
        items: [
          {
            item_id: 'item-a',
            quantity_per_unit: '2',
            unit: 'G',
            waste_factor: '0',
            sort_order: 0,
          },
        ],
      }),
    );
  });
  it('prevents duplicate components and retains selection when search results change', async () => {
    renderForm();
    const first = await screen.findByRole('combobox', { name: 'Component for line 1' });
    fireEvent.focus(first);
    fireEvent.click(await screen.findByRole('option', { name: /RESIN-A/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Add component' }));
    const second = screen.getByRole('combobox', { name: 'Component for line 2' });
    fireEvent.focus(second);
    expect(await screen.findByRole('option', { name: /RESIN-A/ })).toBeDisabled();
    fireEvent.blur(second);
    vi.mocked(itemsApi.catalog).mockResolvedValue(page([itemB]));
    fireEvent.focus(second);
    fireEvent.change(second, {
      target: { value: 'INSERT' },
    });
    await waitFor(() =>
      expect(itemsApi.catalog).toHaveBeenCalledWith(expect.objectContaining({ search: 'INSERT' })),
    );
    expect(first).toHaveValue('RESIN-A — Resin');
    fireEvent.keyDown(second, { key: 'Escape' });
    expect(second).toHaveValue('');
  });
});
