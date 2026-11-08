import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import type { TransferOrder } from '@/types/warehouse';
import type { PaginatedResponse } from '@/types';
import TransferOrdersPage from './transfers';

const api = vi.hoisted(() => ({
  page: vi.fn(),
  get: vi.fn(),
  create: vi.fn(),
  execute: vi.fn(),
  cancel: vi.fn(),
}));
const grants = vi.hoisted(() => new Set<string>());
vi.mock('@/api/inventory/warehouseWms', () => ({ transferOrderApi: api }));
vi.mock('@/api/inventory/warehouse', () => ({ warehouseApi: { tree: vi.fn() } }));
vi.mock('@/api/inventory/items', () => ({ itemsApi: { list: vi.fn() } }));
vi.mock('@/api/inventory/stock', () => ({ stockLevelsApi: { list: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: (permission: string) => grants.has(permission) }) }));
vi.mock('@/hooks/useInventoryStockRealtime', () => ({ useInventoryStockRealtime: vi.fn() }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { id: string; role: { slug: string } } }) => unknown) =>
    selector({ user: { id: 'checker-hash', role: { slug: 'warehouse_staff' } } }),
}));

const transfer: TransferOrder = {
  id: 'transfer-hash',
  transfer_number: 'TR-001',
  from_location: { id: 'from-hash', code: 'A-1', full_code: 'WH-A-1' },
  to_location: { id: 'to-hash', code: 'B-1', full_code: 'WH-B-1' },
  item: { id: 'item-hash', code: 'RESIN-1', name: 'Resin', unit_of_measure: 'kg' },
  quantity: '3.000',
  reason: null,
  status: 'pending',
  status_label: 'Pending',
  created_by: { id: 'maker-hash', name: 'Maker' },
  transferred_by: null,
  transferred_at: null,
  created_at: '2026-11-03T08:00:00Z',
};

function pageData(row: TransferOrder): PaginatedResponse<TransferOrder> {
  return {
    data: [row],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  };
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/inventory/transfer-orders']}>
          <TransferOrdersPage />
        </MemoryRouter>
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

beforeEach(async () => {
  vi.clearAllMocks();
  grants.clear();
  grants.add('inventory.adjust');
  const { transferOrderApi } = await import('@/api/inventory/warehouseWms');
  const { warehouseApi } = await import('@/api/inventory/warehouse');
  const { itemsApi } = await import('@/api/inventory/items');
  vi.mocked(transferOrderApi.page).mockResolvedValue(pageData(transfer));
  vi.mocked(transferOrderApi.get).mockResolvedValue(transfer);
  vi.mocked(warehouseApi.tree).mockResolvedValue([]);
  vi.mocked(itemsApi.list).mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1 } } as never);
});

it('fails closed when the server action projection is missing', async () => {
  vi.mocked(api.page).mockResolvedValue(pageData({ ...transfer, available_actions: undefined } as TransferOrder));
  vi.mocked(api.get).mockResolvedValue({ ...transfer, available_actions: undefined } as TransferOrder);

  renderPage();
  fireEvent.click(await screen.findByRole('button', { name: /TR-001/ }));

  await waitFor(() => expect(api.get).toHaveBeenCalledWith('transfer-hash'));
  expect(await screen.findByText('Resin')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Execute$/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Cancel$/ })).not.toBeInTheDocument();
});

it('renders only transfer actions allowed by the API for this viewer', async () => {
  const actionable = { ...transfer, available_actions: { execute: true, cancel: false } } as TransferOrder;
  vi.mocked(api.page).mockResolvedValue(pageData(actionable));
  vi.mocked(api.get).mockResolvedValue(actionable);

  renderPage();
  fireEvent.click(await screen.findByRole('button', { name: /TR-001/ }));

  expect(await screen.findByRole('button', { name: /^Execute$/ })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Cancel$/ })).not.toBeInTheDocument();
});

it('scopes viewer-dependent transfer queries by user and role', async () => {
  const { queryClient } = renderPage();
  fireEvent.click(await screen.findByRole('button', { name: /TR-001/ }));
  expect(await screen.findByText('Resin')).toBeInTheDocument();

  const detailQuery = queryClient.getQueryCache()
    .findAll({ queryKey: ['inventory', 'transfer-order'] })
    .find(({ queryKey }) => queryKey[2] === 'transfer-hash');
  const listQuery = queryClient.getQueryCache()
    .findAll({ queryKey: ['inventory', 'transfer-orders'] })
    .find(({ queryKey }) => queryKey[2] === 'checker-hash');

  expect(detailQuery?.queryKey).toEqual([
    'inventory', 'transfer-order', 'transfer-hash', 'checker-hash', 'warehouse_staff',
  ]);
  expect(listQuery?.queryKey).toEqual([
    'inventory', 'transfer-orders', 'checker-hash', 'warehouse_staff', 1,
  ]);
});
