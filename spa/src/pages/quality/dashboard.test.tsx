import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import QualityDashboardPage from './dashboard';
import { analyticsApi } from '@/api/quality/analytics';
import { ncrsApi } from '@/api/quality/ncrs';

const realtimeTransport = vi.hoisted(() => {
  const listeners = new Map<string, Set<(payload: unknown) => void>>();

  return {
    listeners,
    emit(channel: string, event: string, payload: unknown = { refresh: true }) {
      for (const listener of listeners.get(`${channel}:${event}`) ?? []) listener(payload);
    },
    clear() {
      listeners.clear();
    },
  };
});

vi.mock('@/lib/realtime/subscriptions', () => ({
  subscribeRealtimeEvent: vi.fn((channel: string, event: string, handler: (payload: unknown) => void) => {
    const key = `${channel}:${event}`;
    const handlers = realtimeTransport.listeners.get(key) ?? new Set();
    handlers.add(handler);
    realtimeTransport.listeners.set(key, handlers);
    return () => {
      handlers.delete(handler);
      if (handlers.size === 0) realtimeTransport.listeners.delete(key);
    };
  }),
}));

vi.mock('@/api/quality/analytics', () => ({
  analyticsApi: {
    defectPareto: vi.fn().mockResolvedValue({ rows: [], from: '2026-10-01', to: '2026-10-31' }),
    inspectionSummary: vi.fn(),
    paretoDrillDown: vi.fn().mockResolvedValue({ rows: [] }),
  },
}));

vi.mock('@/api/quality/ncrs', () => ({
  ncrsApi: { list: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }) },
}));

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));

describe('QualityDashboardPage realtime freshness', () => {
  beforeEach(() => {
    realtimeTransport.clear();
    vi.mocked(analyticsApi.inspectionSummary)
      .mockReset()
      .mockResolvedValueOnce({ pass_rate: 50, total: 2 } as never)
      .mockResolvedValue({ pass_rate: 75, total: 4 } as never);
    vi.mocked(analyticsApi.defectPareto).mockClear();
    vi.mocked(ncrsApi.list).mockClear();
  });

  afterEach(() => {
    realtimeTransport.clear();
  });

  it('refreshes inspection metrics when a private inspection change signal arrives', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <QualityDashboardPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('50.0%')).toBeInTheDocument();

    realtimeTransport.emit('entity.inspection.visible', '.inspection.refresh');

    expect(await screen.findByText('75.0%')).toBeInTheDocument();
    await waitFor(() => expect(analyticsApi.inspectionSummary).toHaveBeenCalledTimes(2));
  });
});
