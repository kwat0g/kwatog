import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import WorkOrderDetailPage from './detail';
import { workOrdersApi } from '@/api/production/workOrders';
import type { WorkOrder } from '@/types/production';

/**
 * O2C audit 2026-09-25 — the "Target / Produced" row rendered produced first,
 * so a WO that made 50 of its 200 read "50 / 200", as if the target were 50.
 */
vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    show: vi.fn(),
    options: vi.fn().mockResolvedValue({ statuses: [] }),
    chain: vi.fn().mockResolvedValue([]),
    downtimeCategories: vi.fn().mockResolvedValue([]),
  },
}));
vi.mock('@/api/production/routings', () => ({ woOperationsApi: { list: vi.fn().mockResolvedValue([]) } }));
vi.mock('@/api/mrp/machines', () => ({ machinesApi: { list: vi.fn().mockResolvedValue({ data: [] }) } }));
vi.mock('@/api/mrp/molds', () => ({ moldsApi: { list: vi.fn().mockResolvedValue({ data: [] }) } }));
vi.mock('@/hooks/useEcho', () => ({ useEcho: () => undefined }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => undefined }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));
vi.mock('@/stores/authStore', () => ({ useAuthStore: () => ({ id: 'u-1' }) }));
vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'wo-1' }),
}));

const workOrder = {
  id: 'wo-1',
  wo_number: 'WO-202609-0001',
  batch_number: 'BATCH-202609-0001',
  material_lot_references: [],
  work_order_class: 'standard',
  exception_reason: null,
  exception_authorized_by: null,
  material_plan_source: null,
  product: { id: 'p-1', part_number: 'WB-001', name: 'Wiper bushing' },
  parent: null,
  children: [],
  sales_order: { id: 'so-1', so_number: 'SO-202609-0001' },
  machine: null,
  mold: null,
  quantity_target: 200,
  quantity_produced: 50,
  quantity_good: 50,
  quantity_rejected: 0,
  progress_percentage: 25,
  scrap_rate: '0.00',
  planned_start: '2026-09-25T00:00:00Z',
  planned_end: '2026-09-26T00:00:00Z',
  actual_start: '2026-09-25T01:00:00Z',
  actual_end: null,
  status: 'in_progress',
  status_label: 'In progress',
  next_statuses: [],
  pause_reason: null,
  priority: 50,
  creator: null,
  materials: [],
  material_cost_summary: null,
  outputs: [],
  inspections: [],
  created_at: '2026-09-25T00:00:00Z',
  updated_at: '2026-09-25T00:00:00Z',
} as unknown as WorkOrder;

describe('WorkOrderDetailPage', () => {
  it('shows the target before the produced quantity, matching its label', async () => {
    vi.mocked(workOrdersApi.show).mockResolvedValue(workOrder);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const label = await screen.findByText('Target / Produced');
    expect(label.nextElementSibling?.textContent).toBe('200 / 50');
  });
});
