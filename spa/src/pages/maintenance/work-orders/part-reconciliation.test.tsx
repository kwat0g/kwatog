import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import DesktopWorkOrderDetail from './detail';
import MobileWorkOrderDetail from '@/pages/maintenance/mobile/work-order';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import type { MaintenanceWorkOrder } from '@/types/maintenance';

const auth = vi.hoisted(() => ({ role: 'maintenance_tech' }));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (select: (state: { user: { role: { slug: string } } }) => unknown) =>
    select({ user: { role: { slug: auth.role } } }),
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true, canAny: () => true }) }));
vi.mock('@/api/maintenance/workOrders', () => ({ workOrdersApi: {
  show: vi.fn(), options: vi.fn(), reconcileParts: vi.fn(),
} }));

afterEach(() => { cleanup(); vi.resetAllMocks(); auth.role = 'maintenance_tech'; });

const outstandingOrder = {
  id: 'mwo-8', mwo_number: 'MWO-202610-0008', maintainable_type: 'machine',
  maintainable: { id: 'machine-5', code: 'IMM-05', name: 'Nissei NEX180' },
  type: 'corrective', priority: 'critical', description: 'Repair coupling',
  status: 'in_progress', available_actions: ['complete', 'submit_repair', 'cancel'], verification_required: true,
  closure_readiness: { latest_work_result: 'passed', can_close_work: true, blocker: null },
  parts_readiness: {
    outstanding: [{ usage_id: 'usage-1', item_code: 'BEAR-9', item_name: 'Bearing', lot_number: 'LOT-24', quantity: '2.000' }],
    can_close_parts: false, blocker: 'Confirm remaining parts consumed before closing or submitting repair.',
  },
  parts_reconciled_at: null, parts_reconciled_by: null,
  started_at: '2026-10-10T04:35:00+08:00', completed_at: null,
  downtime_minutes: 0, cost: '0.00', remarks: null,
  spare_parts: [{
    id: 'usage-1', item: { id: 'item-1', code: 'BEAR-9', name: 'Bearing', unit: 'pcs' },
    work_record: null, issuer: { id: 'tech-1', name: 'Tess Tech' }, quantity: '2.000',
    lot_number: 'LOT-24', unit_cost: '10.00', total_cost: '20.00', returned_quantity: '0.000',
    returned_cost: '0.00', created_at: null,
  }],
  created_at: '2026-10-10T04:25:00+08:00', updated_at: '2026-10-10T04:35:00+08:00', logs: [],
} satisfies MaintenanceWorkOrder;

const reconciledOrder: MaintenanceWorkOrder = {
  ...outstandingOrder,
  parts_readiness: { ...outstandingOrder.parts_readiness, can_close_parts: true, blocker: null },
  parts_reconciled_at: '2026-10-10T04:45:00+08:00',
  parts_reconciled_by: { id: 'tech-1', name: 'Tess Tech' },
};

function show(Page: typeof DesktopWorkOrderDetail, order: MaintenanceWorkOrder = outstandingOrder, reconciled: MaintenanceWorkOrder = reconciledOrder) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(workOrdersApi.show).mockResolvedValueOnce(order).mockResolvedValue(reconciled);
  vi.mocked(workOrdersApi.options).mockResolvedValue({
    maintainable_types: [], types: [], priorities: [], statuses: [], default_type: '', default_priority: '',
  });
  vi.mocked(workOrdersApi.reconcileParts).mockResolvedValue(reconciled);
  render(<QueryClientProvider client={client}>
    <MemoryRouter initialEntries={['/maintenance/work-orders/mwo-8']}>
      <Routes><Route path="/maintenance/work-orders/:id" element={<Page />} /></Routes>
    </MemoryRouter>
  </QueryClientProvider>);
  return client;
}

it.each([
  ['desktop', DesktopWorkOrderDetail],
  ['phone', MobileWorkOrderDetail],
] as const)('%s shows lot closeout and hides handoff until parts are reconciled', async (_layout, Page) => {
  show(Page);
  await screen.findByText('MWO-202610-0008');

  expect(screen.getByText(/BEAR-9.*Bearing/)).toBeVisible();
  expect(screen.getAllByText(/LOT-24/).length).toBeGreaterThan(0);
  expect(screen.getAllByText(/2\.000/).length).toBeGreaterThan(0);
  expect(screen.getByRole('button', { name: /Return.*LOT-24/i })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Confirm remaining parts consumed' })).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Complete' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Submit repair/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Cancel work order|^Cancel$/i })).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Confirm remaining parts consumed' }));
  await waitFor(() => expect(workOrdersApi.reconcileParts).toHaveBeenCalledWith('mwo-8', { confirm_consumed: true }));
  await screen.findByText(/Remaining parts confirmed consumed by Tess Tech/);
});

it.each([
  ['desktop', DesktopWorkOrderDetail],
  ['phone', MobileWorkOrderDetail],
] as const)('%s says no parts to reconcile when net outstanding is zero', async (_layout, Page) => {
  const empty: MaintenanceWorkOrder = {
    ...reconciledOrder,
    available_actions: ['complete'],
    spare_parts: [],
    parts_readiness: { outstanding: [], can_close_parts: true, blocker: null },
    parts_reconciled_at: null,
    parts_reconciled_by: null,
  };
  vi.mocked(workOrdersApi.show).mockResolvedValue(empty);
  vi.mocked(workOrdersApi.options).mockResolvedValue({
    maintainable_types: [], types: [], priorities: [], statuses: [], default_type: '', default_priority: '',
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter initialEntries={['/maintenance/work-orders/mwo-8']}>
      <Routes><Route path="/maintenance/work-orders/:id" element={<Page />} /></Routes>
    </MemoryRouter>
  </QueryClientProvider>);
  await screen.findByText('MWO-202610-0008');

  expect(screen.getByText('No parts to reconcile')).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Confirm remaining parts consumed' })).not.toBeInTheDocument();
});

it.each([
  ['desktop', DesktopWorkOrderDetail],
  ['phone', MobileWorkOrderDetail],
] as const)('%s lets an unavailable technician’s supervisor inspect parts before cancellation', async (_layout, Page) => {
  auth.role = 'maintenance_supervisor';
  const order: MaintenanceWorkOrder = { ...outstandingOrder, available_actions: ['cancel'] };
  const reconciled: MaintenanceWorkOrder = { ...reconciledOrder, available_actions: ['cancel'] };
  show(Page, order, reconciled);
  await screen.findByText('MWO-202610-0008');

  expect(screen.queryByRole('button', { name: /Cancel work order|^Cancel$/i })).not.toBeInTheDocument();
  fireEvent.change(screen.getByRole('textbox', { name: 'Supervisor inspection reason' }), {
    target: { value: 'Technician absent; inspected remaining parts' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Confirm remaining parts consumed' }));
  await waitFor(() => expect(workOrdersApi.reconcileParts).toHaveBeenCalledWith('mwo-8', {
    confirm_consumed: true,
    inspection_reason: 'Technician absent; inspected remaining parts',
  }));
  expect(await screen.findByRole('button', { name: /Cancel work order|^Cancel$/i })).toBeVisible();
});
