import { AxiosError } from 'axios';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import PickingListPage from './picking';
import { stockMovementsApi } from '@/api/inventory/stock';
import type { MaterialHandoverTaskDetail } from '@/types/inventory';
const permissions = vi.hoisted(() => new Set<string>());
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (p: string) => permissions.has(p) }),
}));
vi.mock('@/api/inventory/stock', () => ({
  stockMovementsApi: { handoverTasks: vi.fn(), handoverSources: vi.fn(), handoverGroup: vi.fn() },
}));
const detail: MaterialHandoverTaskDetail = {
  id: 'wo:wo-1',
  label: 'WO-1',
  work_order_id: 'wo-1',
  material_issue_id: null,
  status: 'pending',
  total_lines: 2,
  pending_lines: 2,
  handed_over_lines: 0,
  latest_issue_at: '2026-10-08T10:00:00+08:00',
  default_recipient_id: 'employee-1',
  recipients: [{ value: 'employee-1', label: 'Assigned recorder' }],
  sources: ['RESIN', 'BAGS'].map((code, i) => ({
    movement_id: `issue-${i}`,
    item: { id: code, code, name: code, unit_of_measure: i ? 'pcs' : 'kg' },
    location: { id: 'bin-1', code: 'BIN-1' },
    lot_number: 'LOT-1',
    issued_quantity: '3.000',
    returned_quantity: '1.000',
    remaining_quantity: '2.000',
    eligible: true,
    handover: null,
  })),
};
beforeEach(() => {
  cleanup();
  vi.clearAllMocks();
  permissions.clear();
  permissions.add('inventory.picking.view');
  vi.mocked(stockMovementsApi.handoverTasks).mockResolvedValue({
    data: [detail],
    meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(stockMovementsApi.handoverSources).mockResolvedValue(detail);
  const error = new AxiosError('Issue changed.');
  Object.assign(error, { response: { status: 409, data: { message: 'Issue changed.' } } });
  vi.mocked(stockMovementsApi.handoverGroup).mockRejectedValue(error);
});
function mount(route = '/inventory/picking') {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter initialEntries={[route]}>
        <PickingListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}
it('whole WO row opens all sources and confirms only selected lines with prefilled receiver', async () => {
  permissions.add('inventory.issue.create');
  mount();
  fireEvent.click(await screen.findByText('WO-1'));
  const recipient = await screen.findByRole('combobox', { name: 'Received by' });
  await waitFor(() => expect(recipient).toHaveValue('Assigned recorder'));
  expect(stockMovementsApi.handoverGroup).not.toHaveBeenCalled();
  expect(screen.getByText('RESIN', { selector: 'span' })).toBeVisible();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select BAGS issue-1' }));
  fireEvent.click(screen.getByRole('button', { name: 'Confirm handover (1 lines)' }));
  await screen.findByText('Issue changed.');
  expect(stockMovementsApi.handoverGroup).toHaveBeenCalledWith({
    received_by_employee_id: 'employee-1',
    sources: [{ movement_id: 'issue-0', expected_quantity: '2.000' }],
  });
  expect(recipient).toHaveValue('Assigned recorder');
});
it('read-only Warehouse access opens detail without a receipt action or restricted slip requests', async () => {
  mount('/inventory/picking?work_order_id=wo-1');
  expect(await screen.findByText('Ask Warehouse to confirm material handover.')).toBeVisible();
  expect(screen.queryByRole('button', { name: /Confirm handover/ })).not.toBeInTheDocument();
  expect(stockMovementsApi.handoverSources).toHaveBeenCalledWith({ work_order_id: 'wo-1' });
});
it('legacy linked slip opens complete WO detail', async () => {
  mount('/inventory/picking?material_issue_id=slip-1');
  await screen.findByRole('region', { name: 'Handover details for WO-1' });
  expect(stockMovementsApi.handoverSources).toHaveBeenCalledWith({ material_issue_id: 'slip-1' });
  await waitFor(() =>
    expect(stockMovementsApi.handoverTasks).toHaveBeenCalledWith(
      expect.objectContaining({ work_order_id: 'wo-1' }),
    ),
  );
});

it('fully returned material remains history without claiming physical handover', async () => {
  const returned = {
    ...detail,
    status: 'handed_over' as const,
    pending_lines: 0,
    sources: detail.sources.map((source) => ({
      ...source,
      returned_quantity: source.issued_quantity,
      remaining_quantity: '0.000',
      eligible: false,
    })),
  };
  vi.mocked(stockMovementsApi.handoverTasks).mockResolvedValue({
    data: [returned],
    meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(stockMovementsApi.handoverSources).mockResolvedValue(returned);
  permissions.add('inventory.issue.create');
  mount();
  expect(await screen.findByText('Fully returned')).toBeVisible();
  fireEvent.click(screen.getByText('WO-1'));
  await screen.findByRole('region', { name: 'Handover details for WO-1' });
  expect(screen.queryByRole('button', { name: /Confirm handover/ })).not.toBeInTheDocument();
});
