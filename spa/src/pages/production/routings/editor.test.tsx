import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ProductRouting } from '@/types/production/routing';
import RoutingEditorPage from './editor';

const mocks = vi.hoisted(() => ({
  show: vi.fn(),
  useRealtimeRecord: vi.fn(),
}));

vi.mock('@/api/production/routings', () => ({
  routingsApi: {
    show: mocks.show,
    list: vi.fn(),
    create: vi.fn(),
    update: vi.fn(),
    activate: vi.fn(),
    duplicate: vi.fn(),
  },
}));
vi.mock('@/api/mrp/machines', () => ({
  machinesApi: { list: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) },
}));
vi.mock('@/api/mrp/molds', () => ({
  moldsApi: { list: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) },
}));
vi.mock('@/api/crm/products', () => ({ productsApi: { list: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: mocks.useRealtimeRecord }));

function routing(overrides: Partial<ProductRouting> = {}): ProductRouting {
  return {
    id: 'routing-1',
    version: 1,
    is_active: true,
    updated_at: '2026-11-02T01:00:00.000Z',
    notes: 'Server v1 notes',
    product: { id: 'product-1', part_number: 'P-100', name: 'Widget' },
    operations: [{
      id: 'operation-1',
      sequence: 10,
      operation_name: 'Injection',
      work_center: 'IM-01',
      machine: null,
      mold: null,
      setup_time_minutes: '15.00',
      cycle_time_minutes: '1.50',
      labor_rate_per_hour: '120.0000',
      machine_rate_per_hour: '250.0000',
      overhead_rate_per_hour: '80.0000',
      qc_required: false,
      description: 'Baseline instruction',
    }],
    ...overrides,
  } as ProductRouting;
}

describe('Production routing editor realtime draft safety', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.show.mockResolvedValue(routing());
  });

  it('preserves typed notes on a remote update until the user loads the latest version', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/production/routings/routing-1']}>
          <Routes>
            <Route path="/production/routings/:id" element={<RoutingEditorPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const notes = await screen.findByRole('textbox', { name: 'Notes' });
    fireEvent.change(notes, { target: { value: 'My unsaved setup note' } });
    expect(notes).toHaveValue('My unsaved setup note');

    act(() => {
      queryClient.setQueryData(
        ['production', 'routings', 'detail', 'routing-1'],
        routing({
          version: 2,
          is_active: false,
          updated_at: '2026-11-02T01:05:00.000Z',
          notes: 'Server v2 notes',
        }),
      );
    });

    expect(await screen.findByRole('status')).toHaveTextContent(/changed while you were editing/i);
    expect(screen.getByRole('textbox', { name: 'Notes' })).toHaveValue('My unsaved setup note');

    fireEvent.click(screen.getByRole('button', { name: 'Discard draft and load latest' }));
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Notes' })).toHaveValue('Server v2 notes'));
    queryClient.clear();
  });
});
