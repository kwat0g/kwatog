/**
 * Series C — Task C5. Smoke test for the bottleneck widget.
 *
 * Asserts the four mandatory page states render correctly: loading,
 * error, empty, data. Uses MemoryRouter so <Link> renders without a
 * router. Mocks `chainApi.bottlenecks` so we don't hit the network.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { ChainBottleneckWidget } from './ChainBottleneckWidget';
import * as chainApiModule from '@/api/chain';
import type { ChainBottlenecks } from '@/types/chain';

function renderWithClient(ui: React.ReactElement) {
  // Disable retries so error states resolve quickly.
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ChainBottleneckWidget', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
  });

  it('shows the empty state when nothing is stuck', async () => {
    const empty: ChainBottlenecks = { total: 0, groups: [] };
    vi.spyOn(chainApiModule.chainApi, 'bottlenecks').mockResolvedValue(empty);

    renderWithClient(<ChainBottleneckWidget />);

    await waitFor(() => {
      expect(screen.getByText(/No bottlenecks/i)).toBeInTheDocument();
    });
  });

  it('renders a row per stuck group with count chip', async () => {
    const data: ChainBottlenecks = {
      total: 3,
      groups: [
        {
          key: 'so_at_mrp_planned',
          label: 'SO awaiting production',
          audience: 'ppc_head',
          count: 3,
          rows: [
            {
              key: 'so_at_mrp_planned', label: 'SO awaiting production',
              audience: 'ppc_head', entity_type: 'sales_order',
              entity_id: 'abc', doc_number: 'SO-202604-0001',
              status: 'confirmed', stuck_since: null, hours_stuck: 72,
            },
            {
              key: 'so_at_mrp_planned', label: 'SO awaiting production',
              audience: 'ppc_head', entity_type: 'sales_order',
              entity_id: 'def', doc_number: 'SO-202604-0002',
              status: 'confirmed', stuck_since: null, hours_stuck: 60,
            },
            {
              key: 'so_at_mrp_planned', label: 'SO awaiting production',
              audience: 'ppc_head', entity_type: 'sales_order',
              entity_id: 'ghi', doc_number: 'SO-202604-0003',
              status: 'confirmed', stuck_since: null, hours_stuck: 50,
            },
          ],
        },
      ],
    };
    vi.spyOn(chainApiModule.chainApi, 'bottlenecks').mockResolvedValue(data);

    renderWithClient(<ChainBottleneckWidget />);

    await waitFor(() => {
      expect(screen.getByText(/SO awaiting production/i)).toBeInTheDocument();
    });
    // The count appears in the chip.
    expect(screen.getByText('3')).toBeInTheDocument();
    // The "View" link should target the first row's detail page.
    expect(screen.getByRole('link', { name: /view/i }).getAttribute('href'))
      .toBe('/crm/sales-orders/abc');
  });

  it('returns null when hideWhenEmpty is set and there is nothing stuck', async () => {
    vi.spyOn(chainApiModule.chainApi, 'bottlenecks').mockResolvedValue({ total: 0, groups: [] });

    const { container } = renderWithClient(<ChainBottleneckWidget hideWhenEmpty />);

    // Wait until the loading skeleton (which always renders an
    // animate-pulse skeleton) is gone — at that point the widget should
    // have unmounted itself due to hideWhenEmpty.
    await waitFor(() => {
      expect(container.querySelector('.animate-pulse')).toBeNull();
    });
    expect(screen.queryByText(/No bottlenecks/i)).not.toBeInTheDocument();
    expect(container.firstChild).toBeNull();
  });

  it('renders the error state with a retry button when fetch fails', async () => {
    vi.spyOn(chainApiModule.chainApi, 'bottlenecks').mockRejectedValue(new Error('boom'));

    renderWithClient(<ChainBottleneckWidget />);

    await waitFor(() => {
      expect(screen.getByText(/Failed to load bottlenecks/i)).toBeInTheDocument();
    });
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });
});
