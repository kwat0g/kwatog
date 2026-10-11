import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { metricsApi } from '@/api/metrics';
import type {
  MetricDefinition,
  MetricEnvelope,
  MetricRenderableValue,
} from '@/types/analytics';
import { MetricPanel } from './MetricPanel';

vi.mock('@/api/metrics', () => ({
  metricsApi: { get: vi.fn(), catalog: vi.fn() },
}));

const returnsDefinition: MetricDefinition = {
  code: 'returns.pipeline',
  label: 'Returns pipeline',
  module: 'returns',
  unit: 'count',
  direction: 'lower_better',
  render_kind: 'breakdown',
  permission: 'return_management.view',
  aggregation: 'COUNT(*) grouped by status and by type over the selected window.',
  sources: ['return_requests.status', 'return_requests.type'],
  indexes: ['return_requests_status_index'],
  refresh: 'live',
  caveats: ['Counts returns, not return lines.'],
  cache_ttl_seconds: null,
};

const leadTimeDefinition: MetricDefinition = {
  ...returnsDefinition,
  code: 'production.wo_lead_time',
  label: 'Work-order lead time',
  module: 'production',
  unit: 'hours',
  render_kind: 'trend',
  permission: 'production.dashboard.view',
  caveats: ['Only completed work orders are counted.'],
};

function envelope(
  definition: MetricDefinition,
  value: MetricRenderableValue,
): MetricEnvelope<MetricRenderableValue> {
  return {
    data: { definition, value },
    meta: {
      generated_at: new Date().toISOString(),
      metric: definition.code,
      period: {
        preset: 'month',
        from: '2026-10-01',
        to: '2026-10-31',
        days: 31,
        compared_from: '2026-08-31',
        compared_to: '2026-09-30',
        timezone: 'Asia/Manila',
      },
      cache_ttl: 60,
      cached: false,
      cache_key: 'analytics:returns.pipeline:company:abc',
      scope: 'company',
      module: definition.module,
      refresh: definition.refresh,
      sources: definition.sources,
      indexes: definition.indexes,
      caveats: definition.caveats,
    },
  };
}

function renderPanel(props: Partial<Parameters<typeof MetricPanel>[0]> = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <MetricPanel code="returns.pipeline" {...props} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(metricsApi.get).mockReset();
});

afterEach(() => {
  vi.clearAllMocks();
});

describe('MetricPanel', () => {
  it('renders a breakdown metric with its secondary figures and drill link', async () => {
    vi.mocked(metricsApi.get).mockResolvedValue(
      envelope(returnsDefinition, {
        total: 12,
        open_total: 5,
        awaiting_approval: 3,
        oldest_open_days: 21,
        segments: [
          { label: 'Pending approval', value: 3, tone: 'warning' },
          { label: 'Completed', value: 7, tone: 'success' },
        ],
        by_type: [{ label: 'Customer return', value: 9 }],
        handoff: { awaiting_inspection: 2, in_inspection: 1 },
      }),
    );

    renderPanel({ detailsHref: '/return-management' });

    // Headline total + labelled bars, computed from the payload alone.
    expect(await screen.findByText('12')).toBeInTheDocument();
    expect(screen.getByText('Pending approval')).toBeInTheDocument();
    expect(screen.getByText('Completed')).toBeInTheDocument();
    // Secondary scalars survive, including one-level-flattened handoff counts.
    expect(screen.getByText('Awaiting approval')).toBeInTheDocument();
    expect(screen.getByText('Oldest open days')).toBeInTheDocument();
    expect(screen.getByText('Handoff · Awaiting inspection')).toBeInTheDocument();
    // The window and drill-out are shown; the panel never edits the number.
    expect(screen.getByText('2026-10-01 → 2026-10-31')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open/i })).toHaveAttribute(
      'href',
      '/return-management',
    );
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
  });

  it('renders a trend metric as the latest bucket plus its period count', async () => {
    vi.mocked(metricsApi.get).mockResolvedValue(
      envelope(leadTimeDefinition, {
        unit: 'hours',
        points: [
          { label: 'Aug 2026', value: 14.2 },
          { label: 'Sep 2026', value: 12.8 },
          { label: 'Oct 2026', value: 11.5 },
        ],
        completed: 42,
        avg_hours: 12.6,
        min_hours: null,
        target_hours: null,
        machine_id: null,
      }),
    );

    renderPanel({ code: 'production.wo_lead_time' });

    // Latest bucket (Oct), not the series average.
    expect(await screen.findByText('11.5 h')).toBeInTheDocument();
    expect(screen.getByText('Latest period of 3 periods')).toBeInTheDocument();
    // Null figures and join keys are not rendered as if they were results.
    expect(screen.queryByText('Target hours')).not.toBeInTheDocument();
    expect(screen.queryByText('Machine id')).not.toBeInTheDocument();
  });

  it('shows the empty state when the window carries no observation', async () => {
    vi.mocked(metricsApi.get).mockResolvedValue(
      envelope(returnsDefinition, {
        total: 0,
        open_total: 0,
        awaiting_approval: 0,
        oldest_open_days: null,
        segments: [],
        by_type: [],
        handoff: { awaiting_inspection: 0, in_inspection: 0 },
      }),
    );

    renderPanel();

    expect(await screen.findByText('No data in this window')).toBeInTheDocument();
    expect(
      screen.getByText(/No returns pipeline recorded between 2026-10-01 and 2026-10-31/),
    ).toBeInTheDocument();
  });

  it('renders a retry that refetches when the metric is refused', async () => {
    vi.mocked(metricsApi.get).mockRejectedValue(new Error('403 Forbidden'));

    renderPanel();

    expect(await screen.findByText('Failed to load this metric')).toBeInTheDocument();

    vi.mocked(metricsApi.get).mockResolvedValue(
      envelope(returnsDefinition, { total: 4, segments: [] }),
    );

    screen.getByRole('button', { name: /retry/i }).click();

    await waitFor(() => expect(metricsApi.get).toHaveBeenCalledTimes(2));
    expect(await screen.findByText('4')).toBeInTheDocument();
  });

  it('states the caveats and the gate beside the number', async () => {
    vi.mocked(metricsApi.get).mockResolvedValue(
      envelope(returnsDefinition, { total: 4, segments: [] }),
    );

    renderPanel();

    expect(await screen.findByText('Definition, sources and caveats')).toBeInTheDocument();
    expect(screen.getByText('Counts returns, not return lines.')).toBeInTheDocument();
    expect(
      screen.getByText(/returns.pipeline · gate return_management.view/),
    ).toBeInTheDocument();
  });
});
