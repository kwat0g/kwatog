import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import type { ReactNode, SelectHTMLAttributes } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { deliveriesApi, vehiclesApi } from '@/api/supply-chain';
import type { Delivery } from '@/types/supplyChain';
import CreateDeliveryPage from './create';

vi.mock('@/api/supply-chain', () => ({
  deliveriesApi: { create: vi.fn(), formOptions: vi.fn(), inspectionOptions: vi.fn() },
  vehiclesApi: { list: vi.fn() },
}));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { id: string } }) => unknown) =>
    selector({ user: { id: 'user-1' } }),
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('@/components/ui/Select', async () => {
  const { forwardRef } = await import('react');
  return {
    Select: forwardRef<HTMLSelectElement, {
      label?: string;
      children: ReactNode;
      searchable?: boolean;
      loading?: boolean;
      onSearch?: (value: string) => void;
    } & SelectHTMLAttributes<HTMLSelectElement>>((
      { label, children, searchable, loading, onSearch, ...selectProps }, ref,
    ) => {
      void searchable;
      void loading;
      void onSearch;
      return <label>{label}<select ref={ref} {...selectProps}>{children}</select></label>;
    }),
  };
});
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { discard: vi.fn(), hasDraft: false, draftAge: 0, restore: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdDelivery = {
  id: 'delivery-new',
  delivery_number: 'DR-2026-0001',
  status: 'scheduled',
  version: 1,
  available_actions: { can_confirm: true },
  permissions: { manage: true },
  items: [],
} as unknown as Delivery;

describe('Delivery create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(deliveriesApi.formOptions).mockImplementation(async (params) => {
      if (params?.sales_order_id) {
        return {
          selected_sales_order: {
            id: 'so-1',
            so_number: 'SO-2026-0001',
            customer: { name: 'Test customer' },
            items: [{
              id: 'so-item-1',
              remaining_quantity: 10,
              product: { part_number: 'PC-1', name: 'Test part', unit_of_measure: 'pcs' },
            }],
          },
        } as never;
      }
      return {
        sales_orders: [{
          id: 'so-1', so_number: 'SO-2026-0001', customer: { name: 'Test customer' },
        }],
        has_more: false,
      } as never;
    });
    vi.mocked(deliveriesApi.inspectionOptions).mockResolvedValue([{
      id: 'inspection-1',
      inspection_number: 'QC-2026-0001',
      sales_order_item_id: 'so-item-1',
      remaining_quantity: 10,
    }] as never);
    vi.mocked(vehiclesApi.list).mockResolvedValue({ data: [] } as never);
    vi.mocked(deliveriesApi.create).mockResolvedValue(createdDelivery);
  });

  it('seeds the detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/supply-chain/deliveries/create']}>
          <Routes>
            <Route path="/supply-chain/deliveries/create" element={<CreateDeliveryPage />} />
            <Route path="/supply-chain/deliveries/:id" element={<span>Delivery detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByRole('option', { name: /SO-2026-0001/ });
    fireEvent.change(screen.getByRole('combobox', { name: 'Sales order' }), {
      target: { value: 'so-1' },
    });
    await screen.findByRole('option', { name: /PC-1.*Test part/ });
    fireEvent.change(screen.getByRole('combobox', { name: 'Sales order line' }), {
      target: { value: 'so-item-1' },
    });
    await screen.findByRole('option', { name: /QC-2026-0001/ });
    fireEvent.change(screen.getByRole('combobox', { name: 'Passed outgoing inspection' }), {
      target: { value: 'inspection-1' },
    });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Qty' }), {
      target: { value: '1' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Create delivery' }));

    await waitFor(() => expect(deliveriesApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('Delivery detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData([
      'supply-chain', 'deliveries', 'delivery-new',
    ])).toEqual(createdDelivery));
    queryClient.clear();
  });

  it('checks shipment weight against the selected vehicle capacity before creating', async () => {
    vi.mocked(vehiclesApi.list).mockResolvedValue({ data: [{
      id: 'truck-1', name: 'Payload truck', plate_number: 'CAP-500', capacity_kg: 1500,
    }] } as never);
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
        <MemoryRouter initialEntries={['/supply-chain/deliveries/create']}>
          <Routes>
            <Route path="/supply-chain/deliveries/create" element={<CreateDeliveryPage />} />
            <Route path="/supply-chain/deliveries/:id" element={<span>Delivery detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByRole('option', { name: /SO-2026-0001/ });
    fireEvent.change(screen.getByRole('combobox', { name: 'Sales order' }), { target: { value: 'so-1' } });
    await screen.findByRole('option', { name: /PC-1.*Test part/ });
    fireEvent.change(screen.getByRole('combobox', { name: 'Sales order line' }), { target: { value: 'so-item-1' } });
    await screen.findByRole('option', { name: /QC-2026-0001/ });
    fireEvent.change(screen.getByRole('combobox', { name: 'Passed outgoing inspection' }), { target: { value: 'inspection-1' } });
    fireEvent.change(screen.getByRole('spinbutton', { name: 'Qty' }), { target: { value: '1' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Vehicle' }), { target: { value: 'truck-1' } });

    const weight = screen.getByLabelText('Shipment weight (kg)', { exact: false });
    fireEvent.change(weight, { target: { value: '1500.01' } });
    expect(screen.getByText('Shipment weight exceeds the selected vehicle capacity of 1,500 kg.')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Create delivery' })).toBeDisabled();
    expect(deliveriesApi.create).not.toHaveBeenCalled();

    fireEvent.change(weight, { target: { value: '1499.99' } });
    expect(screen.getByRole('button', { name: 'Create delivery' })).toBeEnabled();
    fireEvent.click(screen.getByRole('button', { name: 'Create delivery' }));
    await waitFor(() => expect(deliveriesApi.create).toHaveBeenCalledWith(expect.objectContaining({
      vehicle_id: 'truck-1', shipment_weight_kg: '1499.99',
    }), expect.any(String)));
  });
});
