import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dssApi, type DssCoverage } from '@/api/dss';
import type {
  PaginatedRecommendations,
  Recommendation,
  RecommendationOptions,
  RecommendationSummary,
} from '@/types/dss';
import DecisionCenterPage from './index';
import { recommendationFixture } from './test-fixtures';

vi.mock('@/api/dss', () => ({
  dssApi: {
    list: vi.fn(),
    summary: vi.fn(),
    options: vi.fn(),
    show: vi.fn(),
    act: vi.fn(),
    outcome: vi.fn(),
    coverage: vi.fn(),
  },
}));

vi.mock('@/hooks/useMediaQuery', () => ({ useMediaQuery: () => false }));

const options: RecommendationOptions = {
  domains: [{ value: 'inventory', label: 'Inventory' }],
  owner_roles: [{ value: 'operations', label: 'Operations' }],
  statuses: [{ value: 'open', label: 'New' }],
  severities: [{ value: 'warning', label: 'Warning' }],
};

const summary: RecommendationSummary = {
  total: 1,
  by_severity: { critical: 0, warning: 1, info: 0 },
};

function result(data: Recommendation[] = []): PaginatedRecommendations {
  return {
    data,
    meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, from: null, to: null },
  };
}

function coverage(overrides: Partial<DssCoverage> = {}): DssCoverage {
  return {
    engine_enabled: true,
    available_detectors: 2,
    shadow_detectors: 0,
    advisory_detectors: 2,
    disabled_detectors: 0,
    failed_detectors: 0,
    partial_detectors: 0,
    checks: [],
    ...overrides,
  };
}

function LocationProbe() {
  const location = useLocation();
  return <output data-testid="location">{location.pathname}{location.search}</output>;
}

function renderPage(path = '/decision-center') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <LocationProbe />
        <DecisionCenterPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Decision Center', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(dssApi.list).mockResolvedValue(result());
    vi.mocked(dssApi.summary).mockResolvedValue(summary);
    vi.mocked(dssApi.options).mockResolvedValue(options);
    vi.mocked(dssApi.show).mockResolvedValue(recommendationFixture());
    vi.mocked(dssApi.act).mockResolvedValue(recommendationFixture());
    vi.mocked(dssApi.outcome).mockResolvedValue(recommendationFixture());
    vi.mocked(dssApi.coverage).mockResolvedValue(coverage());
  });

  it('loads option choices and propagates URL filters to list and summary without pagination', async () => {
    renderPage(
      '/decision-center?search=invoice&severity=warning&domain=inventory&owner_role=operations&status=open&sort=created_at&direction=asc&page=3&per_page=50',
    );

    await screen.findByRole('heading', { name: 'No Warning recommendations' });
    expect(await screen.findByRole('combobox', { name: 'Area' })).toHaveValue('inventory');
    expect(screen.getByRole('combobox', { name: 'Responsible team' })).toHaveValue('operations');
    await waitFor(() => expect(dssApi.list).toHaveBeenCalled());

    const filters = {
      search: 'invoice',
      severity: 'warning',
      domain: 'inventory',
      owner_role: 'operations',
      status: 'open',
      sort: 'created_at',
      direction: 'asc',
      page: 3,
      per_page: 50,
    };
    expect(dssApi.options).toHaveBeenCalledTimes(1);
    expect(dssApi.list).toHaveBeenCalledWith(filters);
    expect(dssApi.summary).toHaveBeenCalledWith({
      search: 'invoice',
      severity: 'warning',
      domain: 'inventory',
      owner_role: 'operations',
      status: 'open',
      sort: 'created_at',
      direction: 'asc',
    });
  });

  it('shows a readable table and decision wording without detector IDs, JSON, or confidence', async () => {
    vi.mocked(dssApi.list).mockResolvedValue(result([recommendationFixture()]));
    renderPage();

    expect(await screen.findByRole('table')).toBeVisible();
    expect(screen.getByRole('columnheader', { name: 'Status' })).toBeVisible();
    expect(screen.getByRole('columnheader', { name: 'Decision' })).toBeVisible();
    expect(screen.getByRole('columnheader', { name: 'Business impact' })).toBeVisible();
    expect(screen.getByRole('link', { name: /Confirmed delivery awaiting customer invoice/ })).toBeVisible();
    expect(screen.getByText('Connected workflows')).toBeVisible();
    expect(screen.getByRole('cell', { name: 'New' })).toBeVisible();
    expect(document.body).not.toHaveTextContent(/dss\.chain_bottlenecks|\{"rule"|100%|1\.0000/);
  });

  it('opens the shared desktop panel through the id query and closes back to the row with filters intact', async () => {
    vi.mocked(dssApi.list).mockResolvedValue(result([recommendationFixture()]));
    renderPage('/decision-center?severity=critical&page=2&per_page=50');

    const rowLink = await screen.findByRole('link', {
      name: /Confirmed delivery awaiting customer invoice/,
    });
    fireEvent.click(rowLink);

    expect(await screen.findByRole('button', { name: 'Close recommendation details' })).toBeVisible();
    expect(screen.getByTestId('location')).toHaveTextContent(
      /\/decision-center\?severity=critical&page=2&per_page=50&id=rec-1/,
    );
    expect(dssApi.show).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Close recommendation details' }));
    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/decision-center?severity=critical&page=2&per_page=50'));
    await waitFor(() => expect(rowLink).toHaveFocus());
  });

  it('keeps a selected recommendation visible when filters produce no list rows', async () => {
    renderPage('/decision-center?severity=critical&id=rec-1');

    expect(await screen.findByRole('heading', { name: 'No Critical recommendations' })).toBeVisible();
    expect(await screen.findByRole('heading', { name: /Confirmed delivery awaiting customer invoice/ })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Close recommendation details' })).toBeVisible();
  });

  it('clears stale domain and owner filters before requesting the list', async () => {
    renderPage('/decision-center?domain=removed-area&owner_role=removed-team&severity=warning&page=4');

    await screen.findByRole('status');
    await waitFor(() => expect(dssApi.list).toHaveBeenCalledTimes(1));
    expect(dssApi.list).toHaveBeenCalledWith(
      expect.objectContaining({ domain: undefined, owner_role: undefined, severity: 'warning', page: 1 }),
    );
    expect(vi.mocked(dssApi.list).mock.calls[0]?.[0]).not.toMatchObject({ domain: 'removed-area', owner_role: 'removed-team' });
    expect(screen.getByTestId('location')).not.toHaveTextContent(/removed-area|removed-team/);
  });

  it('shows coverage warning and retries health checks', async () => {
    vi.mocked(dssApi.coverage)
      .mockRejectedValueOnce(new Error('coverage unavailable'))
      .mockResolvedValueOnce(coverage({ advisory_detectors: 1, shadow_detectors: 1 }));
    renderPage();

    expect(await screen.findByText('Decision check health is unavailable. Recommendation visibility is unchanged.')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Retry health' }));

    await waitFor(() => expect(dssApi.coverage).toHaveBeenCalledTimes(2));
    expect(await screen.findByText(/1 checks are publishing advice/)).toBeVisible();
    expect(screen.queryByText('Decision check health is unavailable. Recommendation visibility is unchanged.')).not.toBeInTheDocument();
  });

  it('hides cached recommendations after a refresh fails', async () => {
    vi.mocked(dssApi.list)
      .mockResolvedValueOnce(result([recommendationFixture()]))
      .mockRejectedValueOnce(new Error('server unavailable'));
    renderPage();
    await screen.findByRole('link', { name: /Confirmed delivery awaiting customer invoice/ });

    fireEvent.click(screen.getByRole('button', { name: 'Refresh Decision Center' }));

    expect(await screen.findByRole('heading', { name: 'Failed to load recommendations' })).toBeVisible();
    expect(screen.queryByRole('link', { name: /Confirmed delivery awaiting customer invoice/ })).not.toBeInTheDocument();
  });
});
