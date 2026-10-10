import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import MaintenanceWorkOrderDetailPage from './detail';
import MobileWorkOrderDetail from '@/pages/maintenance/mobile/work-order';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import type { MaintenanceWorkOrder } from '@/types/maintenance';

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true, canAny: () => true }) }));
vi.mock('@/api/maintenance/workOrders', () => ({ workOrdersApi: { show: vi.fn(), options: vi.fn() } }));

afterEach(() => { cleanup(); vi.resetAllMocks(); });

const order = {
  id: 'mwo-8', mwo_number: 'MWO-202610-0008', maintainable_type: 'machine',
  maintainable: { id: 'machine-5', code: 'IMM-05', name: 'Nissei NEX180' },
  type: 'preventive', priority: 'critical', description: 'Inspect machine',
  status: 'in_progress', available_actions: ['complete'], verification_required: false,
  closure_readiness: { latest_work_result: null, can_close_work: false, blocker: 'Record a passing work result before completion.' },
  started_at: '2026-10-10T04:35:00+08:00', completed_at: null,
  downtime_minutes: 0, cost: '0.00', remarks: null, spare_parts: [],
  created_at: '2026-10-10T04:25:00+08:00', updated_at: '2026-10-10T04:35:00+08:00',
  logs: [{ id: 'start', description: 'Maintenance started.', created_at: '2026-10-10T04:35:00+08:00' }],
} satisfies MaintenanceWorkOrder;

function show(Page: typeof MaintenanceWorkOrderDetailPage, fixture: MaintenanceWorkOrder = order) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(workOrdersApi.show).mockResolvedValue(fixture);
  vi.mocked(workOrdersApi.options).mockResolvedValue({
    maintainable_types: [], types: [], priorities: [], statuses: [], default_type: '', default_priority: '',
  });
  render(<QueryClientProvider client={client}>
    <MemoryRouter initialEntries={['/maintenance/work-orders/mwo-8']}>
      <Routes><Route path="/maintenance/work-orders/:id" element={<Page />} /></Routes>
    </MemoryRouter>
  </QueryClientProvider>);
  return client;
}

it.each([
  ['desktop', MaintenanceWorkOrderDetailPage, 'Complete'],
  ['phone', MobileWorkOrderDetail, 'Complete work order'],
] as const)('%s hides completion until work is recorded', async (_layout, Page, label) => {
  const client = show(Page);
  await screen.findByText('MWO-202610-0008');
  const recordWork = screen.getByRole('button', { name: 'Record work' });
  const history = screen.getByRole('heading', { name: 'Work history' });
  expect(recordWork.compareDocumentPosition(history) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument();
  expect(screen.getByText('Record a passing work result before completion.')).toBeVisible();

  client.setQueryData(['maintenance', 'work-order', 'mwo-8'], { ...order, closure_readiness: { latest_work_result: 'passed', can_close_work: true, blocker: null }, logs: [
    ...order.logs!,
    { id: 'record', description: 'Finding: Guards intact', finding: 'Guards intact', action_taken: 'Cleaned guards', result: 'passed', created_at: '2026-10-10T04:40:00+08:00' },
  ] });
  await waitFor(() => expect(screen.getByRole('button', { name: label })).toBeVisible());
  expect(screen.queryByText('Record a passing work result before completion.')).not.toBeInTheDocument();
});

it.each([
  ['desktop', MaintenanceWorkOrderDetailPage, 'Complete'],
  ['phone', MobileWorkOrderDetail, 'Complete work order'],
] as const)('%s hides completion after a later follow-up result', async (_layout, Page, label) => {
  const passed = { id: 'passed', finding: 'Guard intact', action_taken: 'Tested', result: 'passed' as const, description: 'Passed', created_at: '2026-10-10T04:40:00+08:00' };
  const followUp = { id: 'follow-up', finding: 'Guard loosened', action_taken: 'Investigating', result: 'needs_follow_up' as const, description: 'Follow-up', created_at: '2026-10-10T04:41:00+08:00' };
  const client = show(Page, { ...order, closure_readiness: { latest_work_result: 'passed', can_close_work: true, blocker: null }, logs: [...order.logs, passed] });
  await waitFor(() => expect(screen.getByRole('button', { name: label })).toBeVisible());
  client.setQueryData(['maintenance', 'work-order', 'mwo-8'], { ...order, closure_readiness: { latest_work_result: 'needs_follow_up', can_close_work: false, blocker: 'Latest work result needs follow-up.' }, logs: [...order.logs, passed, followUp] });
  await waitFor(() => expect(screen.queryByRole('button', { name: label })).not.toBeInTheDocument());
});

it.each([
  ['desktop', MaintenanceWorkOrderDetailPage],
  ['phone', MobileWorkOrderDetail],
] as const)('%s hides verified submission until work passes', async (_layout, Page) => {
  show(Page, { ...order, verification_required: true, available_actions: ['submit_repair'] });
  await screen.findByText('MWO-202610-0008');
  expect(screen.queryByRole('button', { name: 'Submit repair' })).not.toBeInTheDocument();
});
