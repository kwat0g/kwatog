import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { itemsApi, itemCategoriesApi } from '@/api/inventory/items';
import { uomsApi } from '@/api/inventory/uoms';
import ItemFormPage from './form';

vi.mock('@/api/inventory/items', () => ({
  itemsApi: { show: vi.fn(), options: vi.fn(), create: vi.fn(), update: vi.fn() },
  itemCategoriesApi: { list: vi.fn() },
}));
vi.mock('@/api/inventory/uoms', () => ({ uomsApi: { list: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({ useFormSafety: () => ({}) }));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
const options = {
  item_types: [{ value: 'raw_material', label: 'Raw material' }],
  reorder_methods: [{ value: 'fixed_quantity', label: 'Fixed quantity' }],
  adjustment_directions: [],
  stock_statuses: [],
};
function renderForm() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <ItemFormPage mode="create" />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(itemsApi.options).mockResolvedValue(options);
  vi.mocked(itemCategoriesApi.list).mockResolvedValue([
    {
      id: 'cat-1',
      name: 'Materials',
      parent_id: null,
      parent_name: null,
      item_type: 'raw_material',
      item_type_label: 'Raw Material',
    },
  ]);
  vi.mocked(uomsApi.list).mockResolvedValue([
    { id: 'u-1', code: 'KG', name: 'Kilogram', deleted_at: null },
  ]);
});
afterEach(cleanup);
describe('Item form', () => {
  it('derives classification from category and validates a positive purchasing multiple before saving', async () => {
    renderForm();
    await screen.findByRole('button', { name: 'Create item' });
    expect(screen.queryByRole('combobox', { name: /^Item type/ })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText(/^Code/), { target: { value: 'rm-new' } });
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'New resin' } });
    fireEvent.focus(screen.getByRole('combobox', { name: /^Category/ }));
    fireEvent.click(await screen.findByRole('option', { name: 'Materials' }));
    expect(await screen.findByText('Raw Material · determined by category')).toBeVisible();
    fireEvent.change(screen.getByRole('combobox', { name: /^Base unit/ }), {
      target: { value: 'KG' },
    });
    fireEvent.change(screen.getByLabelText(/^Standard cost/), { target: { value: '20' } });
    fireEvent.change(screen.getByRole('combobox', { name: /^Reorder method/ }), {
      target: { value: 'fixed_quantity' },
    });
    fireEvent.change(screen.getByLabelText(/^Minimum order quantity/), { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create item' }));
    expect(
      await screen.findByText('Minimum order quantity must be greater than zero.'),
    ).toBeVisible();
    expect(itemsApi.create).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText(/^Minimum order quantity/), { target: { value: '25' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create item' }));
    await waitFor(() => expect(itemsApi.create).toHaveBeenCalled());
    expect(vi.mocked(itemsApi.create).mock.calls[0][0]).not.toHaveProperty('item_type');
    expect(vi.mocked(itemsApi.create).mock.calls[0][0]).toMatchObject({
      code: 'RM-NEW',
      lead_time_days: 0,
      reorder_point: '0',
      safety_stock: '0',
      minimum_order_quantity: '25',
      unit_of_measure: 'KG',
    });
  });
  it('preserves form input and offers retry when reference data fails', async () => {
    vi.mocked(itemCategoriesApi.list).mockRejectedValue(new Error('Unavailable'));
    renderForm();
    fireEvent.change(screen.getByLabelText(/^Name/), { target: { value: 'Keep this name' } });
    expect(await screen.findByText(/Unable to load item setup/)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Create item' })).toBeDisabled();
    vi.mocked(itemCategoriesApi.list).mockResolvedValue([
      {
        id: 'cat-1',
        name: 'Materials',
        parent_id: null,
        parent_name: null,
        item_type: 'raw_material',
        item_type_label: 'Raw Material',
      },
    ]);
    fireEvent.click(screen.getByRole('button', { name: 'Retry setup' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Create item' })).toBeEnabled());
    expect(screen.getByLabelText(/^Name/)).toHaveValue('Keep this name');
  });
});
