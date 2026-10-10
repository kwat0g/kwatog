import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import toast from 'react-hot-toast';
import { itemsApi } from '@/api/inventory/items';
import { stockAdjustmentsApi, stockLevelsApi } from '@/api/inventory/stock';
import { warehouseApi } from '@/api/inventory/warehouse';
import type { Item, StockLevel } from '@/types/inventory';
import CreateStockAdjustmentPage from './create';

vi.mock('@/api/inventory/items', () => ({ itemsApi: { list: vi.fn() } }));
vi.mock('@/api/inventory/stock', () => ({ stockLevelsApi: { list: vi.fn() }, stockAdjustmentsApi: { options: vi.fn(), create: vi.fn() } }));
vi.mock('@/api/inventory/warehouse', () => ({ warehouseApi: { tree: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({ useFormSafety: () => ({}) }));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const item: Item = {
  id: 'i1', code: 'PKG-002', name: 'Shipping Box', description: null, category: null,
  item_type: 'packaging', item_type_label: 'Packaging', unit_of_measure: 'pcs', standard_cost: '15.0000',
  reorder_method: 'fixed_quantity', reorder_point: '0', safety_stock: '0', minimum_order_quantity: '1',
  lead_time_days: 1, is_critical: false, is_active: true, quality_plan_ready: true,
  on_hand_quantity: '20', reserved_quantity: '2', available_quantity: '18', stock_status: 'ok',
};
function stock(locationId = 'l1', cost = '15.0000'): StockLevel {
  return { item: { id: item.id, code: item.code, name: item.name, unit_of_measure: item.unit_of_measure },
    location: { id: locationId, code: locationId, full_code: locationId === 'l1' ? 'MW-A-12' : 'MW-A-13' },
    quantity: '20.000', reserved_quantity: '2.000', available: '18.000', quality_held_quantity: '3.000',
    weighted_avg_cost: cost, total_value: '300.00', lock_version: 0, last_counted_at: null };
}
function page(rows: StockLevel[], lastPage = 1) {
  return { data: rows, meta: { current_page: 1, last_page: lastPage, per_page: 100, total: rows.length, from: 1, to: rows.length },
    links: { first: '', last: '', prev: null, next: null } };
}
function show() {
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter><CreateStockAdjustmentPage /></MemoryRouter>
  </QueryClientProvider>);
}
async function choose(label: string, name: string) {
  fireEvent.focus(screen.getByRole('combobox', { name: new RegExp(`^${label}`) }));
  fireEvent.click(await screen.findByRole('option', { name: new RegExp(name) }));
}
async function selectItem() { await choose('Item', 'PKG-002'); }
function direction(value: string) {
  fireEvent.change(screen.getByRole('combobox', { name: /^Direction/ }), { target: { value } });
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(itemsApi.list).mockResolvedValue({ ...page([]), data: [item] });
  vi.mocked(stockLevelsApi.list).mockResolvedValue(page([stock()]));
  vi.mocked(stockAdjustmentsApi.options).mockResolvedValue({
    statuses: [], directions: [{ value: 'in', label: 'In' }, { value: 'out', label: 'Out' }],
    reasons: [{ value: 'system_correction', label: 'System Correction' }], approval_threshold: '1000.00',
  });
  vi.mocked(warehouseApi.tree).mockResolvedValue([{ id: 'w1', name: 'Main Warehouse', code: 'MW', address: null, is_active: true,
    zones: [{ id: 'z1', name: 'A', code: 'A', zone_type: 'raw_materials', locations: ['l1', 'l2', 'l3'].map(id => ({
      id, code: id === 'l1' ? '12' : id === 'l2' ? '13' : '14', zone_id: 'z1', rack: null, bin: null,
      is_active: true, is_blocked: false, full_code: `MW-A-${id}`,
    })) }] }]);
});
afterEach(cleanup);

it('autofills the only stocked location and its cost; outbound cost is read-only', async () => {
  show(); await selectItem(); direction('out');
  await waitFor(() => expect(screen.getByRole('combobox', { name: /^Location/ })).toHaveValue('MW-A-12 · Main Warehouse'));
  expect(screen.getByLabelText(/^Unit cost/)).toHaveValue(15);
  expect(screen.getByLabelText(/^Unit cost/)).toHaveAttribute('readonly');
  expect(screen.getByText('15 pcs')).toBeVisible();
  expect(screen.getByRole('combobox', { name: /^Reason code/ })).toHaveValue('');
});

it('keeps location selection explicit when stock spans pages and refreshes cost per location', async () => {
  vi.mocked(stockLevelsApi.list).mockImplementation(async params => params?.page === 2 ? page([stock('l2', '22.0000')]) : page([stock()], 2));
  show(); await selectItem(); direction('out');
  await waitFor(() => expect(stockLevelsApi.list).toHaveBeenCalledWith(expect.objectContaining({ page: 2 })));
  expect(screen.getByRole('combobox', { name: /^Location/ })).toHaveValue('Select location…');
  await choose('Location', 'MW-A-13');
  expect(screen.getByLabelText(/^Unit cost/)).toHaveValue(22);
  fireEvent.focus(screen.getByRole('combobox', { name: /^Location/ }));
  expect(screen.queryByRole('option', { name: /MW-A-14/ })).not.toBeInTheDocument();
});

it('clears stale location and cost when another item is selected', async () => {
  vi.mocked(itemsApi.list).mockResolvedValue({ ...page([]), data: [item, { ...item, id: 'i2', code: 'PKG-003' }] });
  vi.mocked(stockLevelsApi.list).mockImplementation(async params => page(params?.item_id === 'i2' ? [] : [stock()]));
  show(); await selectItem(); direction('out');
  await waitFor(() => expect(screen.getByLabelText(/^Unit cost/)).toHaveValue(15));
  await choose('Item', 'PKG-003');
  await waitFor(() => expect(screen.getByRole('combobox', { name: /^Location/ })).toHaveValue('Select location…'));
  expect(screen.getByLabelText(/^Unit cost/)).toHaveValue(null);
  expect(screen.getByRole('button', { name: 'Review adjustment' })).toBeDisabled();
});

it('rejects fractional pieces and quantities exceeding unreserved, unheld stock', async () => {
  show(); await selectItem(); direction('out');
  await waitFor(() => expect(screen.getByLabelText(/^Unit cost/)).toHaveValue(15));
  fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '0.5' } });
  expect(await screen.findByText('Piece quantities must be whole numbers.')).toBeVisible();
  fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '16' } });
  expect(await screen.findByText(/Only 15 pcs can be removed/)).toBeVisible();
  expect(screen.getByRole('button', { name: 'Review adjustment' })).toBeDisabled();
  expect(stockAdjustmentsApi.create).not.toHaveBeenCalled();
});

it('requires explicit inbound cost when the selected location has no stock history', async () => {
  vi.mocked(stockLevelsApi.list).mockResolvedValue(page([]));
  show(); await selectItem(); direction('in'); await choose('Location', 'MW-A-14');
  expect(screen.getByLabelText(/^Unit cost/)).toHaveValue(null);
  expect(screen.getByLabelText(/^Unit cost/)).not.toHaveAttribute('readonly');
  fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '5' } });
  fireEvent.change(screen.getByRole('combobox', { name: /^Reason code/ }), { target: { value: 'system_correction' } });
  fireEvent.change(screen.getByRole('textbox', { name: /^Reason \(/ }), { target: { value: 'Stock adjustment test reason' } });
  fireEvent.click(screen.getByRole('button', { name: 'Review adjustment' }));
  expect(await screen.findByText('Enter a unit cost for this new stock location.')).toBeVisible();
  expect(stockAdjustmentsApi.create).not.toHaveBeenCalled();
});

it('blocks submission when item stock cannot load and offers retry', async () => {
  vi.mocked(stockLevelsApi.list).mockRejectedValue(new Error('Offline'));
  show(); await selectItem();
  expect(await screen.findByRole('button', { name: 'Retry options' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Review adjustment' })).toBeDisabled();
});

it('shows pending approval accurately and reuses the same request key after a failed submission', async () => {
  const pending = {
    id: 'a1', item: { id: item.id, code: item.code, name: item.name }, location: { id: 'l1', code: '12' },
    direction: 'out' as const, quantity: '5', unit_cost: '15', value: '75', reason_code: 'system_correction',
    reason: 'Stock adjustment test reason', status: 'pending' as const, requested_by: null, approved_by: null,
    approved_at: null, created_at: '', stock_movement: null, resolved_by: null, resolved_at: null, resolution_reason: null,
    can_approve: false, can_reject: false, can_withdraw: true,
  };
  vi.mocked(stockAdjustmentsApi.create).mockRejectedValueOnce(new Error('Offline')).mockResolvedValueOnce(pending);
  vi.mocked(stockLevelsApi.list).mockResolvedValueOnce(page([stock()]))
    .mockResolvedValue(page([{ ...stock(), quantity: '0', available: '0', reserved_quantity: '0', quality_held_quantity: '0' }]));
  show(); await selectItem(); direction('out');
  await waitFor(() => expect(screen.getByLabelText(/^Unit cost/)).toHaveValue(15));
  fireEvent.change(screen.getByLabelText(/^Quantity/), { target: { value: '5' } });
  fireEvent.change(screen.getByRole('combobox', { name: /^Reason code/ }), { target: { value: 'system_correction' } });
  fireEvent.change(screen.getByRole('textbox', { name: /^Reason \(/ }), { target: { value: pending.reason } });
  fireEvent.click(screen.getByRole('button', { name: 'Review adjustment' }));
  const dialog = await screen.findByRole('dialog');
  expect(within(dialog).getByText(/PKG-002/)).toBeVisible();
  fireEvent.click(within(dialog).getByRole('button', { name: 'Submit adjustment' }));
  await waitFor(() => expect(stockLevelsApi.list).toHaveBeenCalledTimes(2));
  await waitFor(() => expect(screen.getByRole('combobox', { name: /^Location/ })).toHaveValue('Select location…'));
  expect(screen.getByRole('button', { name: 'Review adjustment' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Retry adjustment' }));
  await waitFor(() => expect(stockAdjustmentsApi.create).toHaveBeenCalledTimes(2));
  const [first, second] = vi.mocked(stockAdjustmentsApi.create).mock.calls;
  expect(first[0]).toEqual(expect.objectContaining({ item_id: 'i1', location_id: 'l1', direction: 'out' }));
  expect(first[0]).not.toHaveProperty('unit_cost');
  expect(first[1]).toBeTruthy(); expect(second[1]).toBe(first[1]);
  expect(toast.success).toHaveBeenCalledWith('Adjustment submitted for Finance approval. Stock has not changed.');
});
