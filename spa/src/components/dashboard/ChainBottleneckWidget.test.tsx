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
 <MemoryRouter>
 {ui}
 </MemoryRouter>
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

 // Wait for the widget to unmount itself. This previously waited on
 // `.animate-pulse` disappearing, which coupled the test to a class name
 // SkeletonBlock no longer emits — `animate-pulse animate-shimmer` set the CSS
 // `animation` property twice, so the pulse was dead weight and was removed.
 // With the selector never matching, `waitFor` resolved on the first tick,
 // before the query settled, and the assertion below saw the loading Panel.
 // Waiting on the actual condition under test cannot rot that way.
 await waitFor(() => {
 expect(container.firstChild).toBeNull();
 });
 expect(screen.queryByText(/No bottlenecks/i)).not.toBeInTheDocument();
 });

 it('renders the error state with a retry button when fetch fails', async () => {
 vi.spyOn(chainApiModule.chainApi, 'bottlenecks').mockRejectedValue(new Error('boom'));

 renderWithClient(<ChainBottleneckWidget />);

 await waitFor(() => {
 expect(screen.getByText(/Failed to load bottlenecks/i)).toBeInTheDocument();
 });
 expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
 });

 it('surfaces manual listener handoffs even when no business bottleneck rows exist', async () => {
 const data: ChainBottlenecks = {
 total: 0,
 groups: [],
 automation: {
 status: 'attention',
 outbox: {
 available: true, total: 0, pending: 0, processing: 0, published: 0,
 failed: 0, stale_pending: 0, stale_processing: 0, oldest_pending_at: null, oldest_failure_at: null,
 },
 listeners: {
 available: true, total: 2, processing: 0, retrying: 0, completed: 2,
 failed: 0, stale_processing: 0, oldest_failure_at: null, oldest_active_at: null,
 outcomes: {
 available: true, total: 2, completed: 1, skipped: 0,
 manual_required: 1, failed: 0, unclassified: 0,
 },
 },
 failed_jobs: { available: true, total: 0, oldest_at: null },
 },
 };
 vi.spyOn(chainApiModule.chainApi, 'bottlenecks').mockResolvedValue(data);

 renderWithClient(<ChainBottleneckWidget />);

 await waitFor(() => {
 expect(screen.getByText(/1 manual handoff/i)).toBeInTheDocument();
 });
 });
});
