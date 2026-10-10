import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import MaintenanceWorkOrderDetailPage from './detail';
import MobileWorkOrderDetail from '@/pages/maintenance/mobile/work-order';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import type { MaintenanceWorkOrder } from '@/types/maintenance';

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({
  can: (permission: string) => permission === 'maintenance.work.assigned',
  canAny: (...permissions: string[]) => permissions.includes('maintenance.work.assigned'),
}) }));
vi.mock('@/api/maintenance/workOrders', () => ({ workOrdersApi: { show: vi.fn(), options: vi.fn() } }));

afterEach(() => { cleanup(); vi.resetAllMocks(); });

const order = {
  id: 'employee-mwo', mwo_number: 'EMP-MWO-1', maintainable_type: 'machine',
  maintainable: { id: 'machine-1', code: 'IMM-01', name: 'Test machine' },
  type: 'corrective', priority: 'low', description: 'Inspect machine',
  status: 'in_progress', available_actions: [], can_record_work: true, verification_required: false,
  closure_readiness: { latest_work_result: null, can_close_work: false, blocker: 'Record work first.' },
  started_at: '2026-10-10T10:00:00+08:00', completed_at: null,
  downtime_minutes: 0, cost: '0.00', remarks: null, spare_parts: [], logs: [],
  created_at: '2026-10-10T09:00:00+08:00', updated_at: '2026-10-10T10:00:00+08:00',
} satisfies MaintenanceWorkOrder;

it.each([
  ['desktop', MaintenanceWorkOrderDetailPage],
  ['phone', MobileWorkOrderDetail],
] as const)('%s grants only recording and removes controls when assignment access ends', async (_layout, Page) => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(workOrdersApi.show).mockResolvedValue(order);
  vi.mocked(workOrdersApi.options).mockResolvedValue({
    maintainable_types: [], types: [], priorities: [], statuses: [], default_type: '', default_priority: '',
  });
  render(<QueryClientProvider client={client}>
    <MemoryRouter initialEntries={['/self-service/maintenance-work-orders/employee-mwo']}>
      <Routes><Route path="/self-service/maintenance-work-orders/:id" element={<Page />} /></Routes>
    </MemoryRouter>
  </QueryClientProvider>);

  expect(await screen.findByRole('button', { name: 'Record work' })).toBeVisible();
  expect(screen.getByRole('button', { name: /^Request spare parts$/i })).toBeVisible();
  expect(screen.queryByRole('button', { name: /Start|Complete|Cancel work order|Submit repair|Assign technician/i })).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Work orders|Back to list/i })).toHaveAttribute('href', '/self-service/maintenance-work-orders');

  client.setQueryData(['maintenance', 'work-order', 'employee-mwo'], { ...order, can_record_work: false });
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Record work' })).not.toBeInTheDocument());
  expect(screen.queryByRole('button', { name: /^Request spare parts$/i })).not.toBeInTheDocument();
  expect(screen.getByText('Inspect machine')).toBeVisible();
});
