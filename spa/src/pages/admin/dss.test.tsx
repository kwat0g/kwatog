import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import toast from 'react-hot-toast';
import { dssApi, type DssDetectorState, type DssRunResult } from '@/api/dss';
import DssAdminPage from './dss';

vi.mock('@/api/dss', () => ({
  dssApi: {
    detectors: vi.fn(),
    activitySummary: vi.fn(),
    engine: vi.fn(),
    updateEngine: vi.fn(),
    updateDetector: vi.fn(),
    runDetector: vi.fn(),
  },
}));

vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('react-hot-toast', () => ({
  default: Object.assign(vi.fn(), { success: vi.fn(), error: vi.fn() }),
}));

const detector: DssDetectorState = {
  id: 'dss.master_hygiene',
  domain: 'platform',
  version: 'v1',
  owner_permissions: [],
  enabled: true,
  mode: 'shadow',
  active_count: 4,
  open_count: 4,
  last_run: {
    status: 'running',
    started_at: '2026-10-23T00:00:00Z',
    finished_at: null,
    duration_ms: null,
    rows_scanned: 0,
    emitted: 0,
    error_summary: null,
    run_reference: 'abc123',
    stale: true,
  },
};

function result(status: DssRunResult['detectors'][string]['status']): DssRunResult {
  return {
    engine: 'enabled',
    detectors: {
      [detector.id]: {
        status,
        emitted: 0,
        updated: 0,
        rows_scanned: 0,
        complete: status === 'completed',
        error_summary: null,
      },
    },
    failed: status === 'failed' ? [detector.id] : [],
  };
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidateQueries = vi.fn().mockResolvedValue(undefined);
  queryClient.invalidateQueries = invalidateQueries;
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DssAdminPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return invalidateQueries;
}

describe('DSS admin detector runs', () => {
  afterEach(() => vi.useRealTimers());

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(dssApi.detectors).mockResolvedValue([detector]);
    vi.mocked(dssApi.activitySummary).mockResolvedValue({
      period_days: 30,
      recommendation_opens: 0,
      follow_ups_started: 0,
      source_verified_resolutions: 0,
      recurring_recommendations: 0,
    });
    vi.mocked(dssApi.engine).mockResolvedValue({
      enabled: true,
      default_mode: 'shadow',
      legacy_global_advisory_ignored: false,
    });
  });

  it.each([
    ['queued', 'success'],
    ['completed', 'success'],
    ['partial', 'warning'],
    ['skipped', 'warning'],
    ['failed', 'error'],
  ] as const)('shows %s run feedback and refreshes health data', async (status, feedback) => {
    vi.mocked(dssApi.runDetector).mockResolvedValue(result(status));
    const invalidateQueries = renderPage();

    expect(await screen.findByText('4 active')).toBeVisible();
    expect(screen.getByText(/last running \(stale\)/)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Run now' }));

    await waitFor(() => expect(dssApi.runDetector).toHaveBeenCalledWith(detector.id));
    if (status === 'queued') expect(toast.success).toHaveBeenCalledWith('Master data completeness run queued');
    else if (feedback === 'success') expect(toast.success).toHaveBeenCalled();
    if (feedback === 'error') expect(toast.error).toHaveBeenCalled();
    if (feedback === 'warning') expect(toast).toHaveBeenCalled();
    await waitFor(() => {
      expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['dss', 'admin', 'detectors'] });
      expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['dss', 'coverage'] });
    });
  });

  it('refreshes detector status every five seconds while the admin page is open', async () => {
    vi.useFakeTimers();
    renderPage();

    await act(async () => {
      await Promise.resolve();
      await vi.advanceTimersByTimeAsync(5000);
    });

    expect(dssApi.detectors).toHaveBeenCalledTimes(2);
    vi.useRealTimers();
  });
});
