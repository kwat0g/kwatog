import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { SparePartSheet } from './SparePartSheet';
import MaintenanceWorkOrderDetailPage from './detail';
import MobileWorkOrderDetail from '@/pages/maintenance/mobile/work-order';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { client } from '@/api/client';

vi.mock('@/api/maintenance/workOrders', () => ({
  workOrdersApi: { spareParts: vi.fn(), sparePartStock: vi.fn(), show: vi.fn(), options: vi.fn() },
}));
vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>();
  return { ...actual, client: { ...actual.client, post: vi.fn() } };
});
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});

it.each([
  ['desktop', true],
  ['phone', false],
] as const)(
  '%s selects a spare part from a searchable dropdown after one debounce',
  async (_, desktop) => {
    vi.mocked(workOrdersApi.spareParts).mockResolvedValue({
      data: [
        {
          id: 'part-1',
          code: 'DEMO-SP-ORING-24',
          name: 'Demo NBR O-Ring 24 x 2 mm',
          unit_of_measure: 'PCS',
          stock_status: 'available',
          stock: [{ location: { id: 'location-1', code: 'MW-D-D-01' }, available: '10.000' }],
        },
      ],
    });
    vi.mocked(workOrdersApi.sparePartStock).mockResolvedValue({
      data: [{ location: { id: 'location-1', code: 'MW-D-D-01' }, available: '10.000' }],
    });

    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <SparePartSheet workOrderId="mwo-8" isOpen onClose={() => {}} desktop={desktop} />
      </QueryClientProvider>,
    );

    const search = screen.getByRole('combobox', { name: 'Search spare parts' });
    fireEvent.focus(search);
    expect(await screen.findByRole('option', { name: /DEMO-SP-ORING-24/ })).toBeVisible();
    fireEvent.change(search, { target: { value: 'ORING' } });
    expect(workOrdersApi.spareParts).not.toHaveBeenCalledWith('ORING', 'mwo-8');
    await waitFor(() => expect(workOrdersApi.spareParts).toHaveBeenCalledWith('ORING', 'mwo-8'));
    fireEvent.click(await screen.findByRole('option', { name: /DEMO-SP-ORING-24/ }));

    expect(screen.getByText('Source location')).toBeVisible();
    expect(await screen.findByRole('option', { name: /MW-D-D-01/ })).toBeVisible();
  },
);

it('blocks issue and directs technicians to record work when no structured record exists', async () => {
  const onClose = vi.fn();
  vi.mocked(workOrdersApi.spareParts).mockResolvedValue({
    data: [{ id: 'part-1', code: 'SEAL', name: 'Seal', unit_of_measure: 'PCS' }],
  });
  vi.mocked(workOrdersApi.sparePartStock).mockResolvedValue({
    data: [{ location: { id: 'location-1', code: 'STORE' }, available: '10.000' }],
  });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <SparePartSheet workOrderId="mwo-8" isOpen onClose={onClose} desktop logs={[]} />
    </QueryClientProvider>,
  );

  fireEvent.focus(screen.getByRole('combobox', { name: 'Search spare parts' }));
  fireEvent.click(await screen.findByRole('option', { name: /SEAL/ }));
  fireEvent.change(await screen.findByLabelText('Source location'), { target: { value: 'location-1' } });
  fireEvent.change(screen.getByLabelText('Quantity (PCS)'), { target: { value: '1' } });

  expect(screen.getByText(/No structured work record/)).toBeVisible();
  expect(screen.getByRole('button', { name: 'Request spare parts' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Close to record work' }));
  expect(onClose).toHaveBeenCalledOnce();
  expect(client.post).not.toHaveBeenCalled();
});

it('defaults to latest structured record and sends selected work_record_id', async () => {
  vi.mocked(workOrdersApi.spareParts).mockResolvedValue({
    data: [{ id: 'part-1', code: 'SEAL', name: 'Seal', unit_of_measure: 'PCS' }],
  });
  vi.mocked(workOrdersApi.sparePartStock).mockResolvedValue({
    data: [{ location: { id: 'location-1', code: 'STORE' }, available: '10.000' }],
  });
  vi.mocked(client.post).mockResolvedValue({ data: { data: { id: 'usage-1' } } });

  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <SparePartSheet
        workOrderId="mwo-8"
        isOpen
        onClose={() => {}}
        desktop
        logs={[
          { id: '2', description: 'Latest', finding: 'Latest finding', action_taken: 'Latest action', created_at: '2026-10-10T10:00:00Z' },
          { id: '1', description: 'First', finding: 'Old finding', action_taken: 'Old action', created_at: '2026-10-09T10:00:00Z' },
        ]}
      />
    </QueryClientProvider>,
  );

  fireEvent.focus(screen.getByRole('combobox', { name: 'Search spare parts' }));
  fireEvent.click(await screen.findByRole('option', { name: /SEAL/ }));
  expect(await screen.findByRole('combobox', { name: 'Work record' })).toHaveValue('2');
  expect(screen.getByText('Finding:').closest('p')).toHaveTextContent('Latest finding');
  expect(screen.getByText('Latest action')).toBeVisible();

  fireEvent.change(screen.getByRole('combobox', { name: 'Work record' }), { target: { value: '1' } });
  fireEvent.change(screen.getByLabelText('Source location'), { target: { value: 'location-1' } });
  fireEvent.change(screen.getByLabelText('Quantity (PCS)'), { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Request spare parts' }));

  await waitFor(() => expect(client.post).toHaveBeenCalledWith(
    '/maintenance/work-orders/mwo-8/spare-parts',
    expect.objectContaining({
      item_id: 'part-1',
      location_id: 'location-1',
      quantity: '1',
      work_record_id: '1',
    }),
  ));
});

it.each([
  ['desktop', MaintenanceWorkOrderDetailPage],
  ['phone', MobileWorkOrderDetail],
] as const)('%s labels a historical issue without a work record', async (_layout, Page) => {
  vi.mocked(workOrdersApi.show).mockResolvedValue({
    id: 'mwo-8',
    mwo_number: 'MWO-202610-0008',
    maintainable_type: 'machine',
    maintainable: { id: 'machine-5', code: 'IMM-05', name: 'Nissei NEX180' },
    type: 'corrective',
    priority: 'high',
    description: 'Repair machine',
    status: 'in_progress',
    available_actions: ['complete'],
    started_at: null,
    completed_at: null,
    downtime_minutes: 0,
    cost: '4.00',
    remarks: null,
    logs: [],
    spare_parts: [{
      id: 'usage-1',
      item: { id: 'part-1', code: 'SEAL', name: 'Seal', unit: 'PCS' },
      quantity: '2.000',
      lot_number: 'LOT-7',
      stock_movement_id: null,
      work_record: null,
      issuer: { id: 'user-1', name: 'Rae Tech' },
      unit_cost: '2.00',
      total_cost: '4.00',
      returned_quantity: '0.000',
      returned_cost: '0.00',
      created_at: null,
    }],
    created_at: null,
    updated_at: null,
  });
  vi.mocked(workOrdersApi.options).mockResolvedValue({
    maintainable_types: [], types: [], priorities: [], statuses: [], default_type: '', default_priority: '',
  });
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/maintenance/work-orders/mwo-8']}>
        <Routes><Route path="/maintenance/work-orders/:id" element={<Page />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByText(/Work record not captured\./)).toBeVisible();
  expect(screen.getByText(/Issued by Rae Tech/)).toBeVisible();
  expect(screen.getByText(/LOT-7/)).toBeVisible();
});
