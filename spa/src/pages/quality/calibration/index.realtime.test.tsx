import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CalibrationListPage from './index';
import { calibrationApi } from '@/api/quality/calibration';

vi.mock('@/api/quality/calibration', () => ({
  calibrationApi: {
    list: vi.fn(),
    options: vi.fn(),
    record: vi.fn(),
  },
}));

vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: () => false }),
}));

vi.mock('@/hooks/useRealtimeRecord', () => ({
  useRealtimeList: vi.fn(),
}));

describe('CalibrationListPage realtime action contract', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(calibrationApi.list).mockResolvedValue({
      data: [{
        id: 'cal-1',
        equipment_code: 'CAL-001',
        name: 'Digital caliper',
        location: 'QC lab',
        last_calibration_date: null,
        next_calibration_date: null,
        frequency_days: 365,
        status: 'active',
        status_label: 'Active',
        responsible: 'QC',
        remarks: null,
        created_at: null,
        updated_at: null,
        permissions: { view: true, manage: true },
        available_actions: { update: true, record_calibration: true },
      }],
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
      permissions: { create: true },
    } as never);
  });

  it('renders create and row controls from the authorized API response', async () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter>
          <CalibrationListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('button', { name: 'New instrument' })).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Edit CAL-001' }).length).toBeGreaterThan(0);
    expect(screen.getAllByRole('button', { name: 'Record' }).length).toBeGreaterThan(0);
  });
});
