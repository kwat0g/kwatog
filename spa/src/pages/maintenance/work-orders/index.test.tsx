import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import MaintenanceWorkOrdersListPage from './index';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import type { MaintenanceWorkOrder } from '@/types/maintenance';

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useEcho', () => ({ useEcho: () => undefined }));
vi.mock('@/api/maintenance/workOrders', () => ({ workOrdersApi: { list: vi.fn(), options: vi.fn() } }));

afterEach(() => { cleanup(); vi.resetAllMocks(); });

const makeOrder = (id: string, priority: 'critical' | 'high', cost: string): MaintenanceWorkOrder => ({
  id,
  mwo_number: `MWO-${id}`,
  maintainable_type: 'machine',
  maintainable: { id: `machine-${id}`, code: `M-${id}`, name: `Machine ${id}` },
  type: 'corrective',
  priority,
  description: 'Repair machine',
  status: 'open',
  started_at: null,
  completed_at: null,
  downtime_minutes: 0,
  cost,
  remarks: null,
  spare_parts: [],
  created_at: null,
  updated_at: null,
});

it('labels page-scoped cards and counts only critical orders on the loaded page', async () => {
  vi.mocked(workOrdersApi.list).mockResolvedValue({
    data: [makeOrder('1', 'critical', '120.00'), makeOrder('2', 'high', '80.00')],
    meta: { current_page: 1, last_page: 3, per_page: 25, total: 57, from: 1, to: 2 },
    links: { first: '', last: '', prev: null, next: '' },
  });
  vi.mocked(workOrdersApi.options).mockResolvedValue({
    maintainable_types: [], types: [], priorities: [
      { value: 'critical', label: 'Critical' }, { value: 'high', label: 'High' },
    ], statuses: [], default_type: '', default_priority: '',
  });

  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter><MaintenanceWorkOrdersListPage /></MemoryRouter>
  </QueryClientProvider>);

  expect(await screen.findByText('57 orders')).toBeVisible();
  expect(screen.getByText('Critical priority')).toBeVisible();
  expect(screen.getByRole('link', { name: /Critical priority/ })).toHaveAttribute('href', '/?queue=critical');
  // Read each figure from inside its own labelled card rather than by the
  // value's class: the label is the card's public identity, and the figure's
  // size is now responsive to the card width (F-009), so `text-2xl` is not a
  // stable hook. Scoping to the card also proves the number landed in the
  // right card, which a bare text query did not.
  const criticalCard = screen.getByText('Critical priority').parentElement as HTMLElement;
  expect(within(criticalCard).getByText('1')).toBeVisible();
  const partsCostCard = screen.getByText('Parts cost on this page').parentElement as HTMLElement;
  expect(within(partsCostCard).getByText('200.00')).toBeVisible();
  expect(screen.queryByText('Total Cost')).not.toBeInTheDocument();
  expect(screen.queryByText('High priority')).not.toBeInTheDocument();
});
