/**
 * Series C — Task C5. Chain Bottleneck Widget.
 *
 * Renders the per-step count of stuck records on the dashboard. Pass
 * `audience` to scope the widget to a single role (Finance only sees
 * its own bottlenecks, etc.). Without `audience`, every group is shown.
 *
 * Mounts inside any dashboard page; talks to GET /chain/bottlenecks.
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { chainApi } from '@/api/chain';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import type { ChainAutomationSummary, ChainBottleneckGroup, ChainBottleneckRow } from '@/types/chain';

interface Props {
 audience?: string;
 /** Optional title override. Default: "Chain bottlenecks". */
 title?: string;
 /** Hide the widget entirely when there is nothing stuck. */
 hideWhenEmpty?: boolean;
}

export function ChainBottleneckWidget({ audience, title = 'Chain bottlenecks', hideWhenEmpty = false }: Props) {
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['chain-bottlenecks', audience ?? 'all'],
 queryFn: () => chainApi.bottlenecks(audience),
 refetchInterval: 60_000,
 staleTime: 60_000,
 });

 // ─── LOADING ───
 if (isLoading) {
 return (
 <Panel title={title} meta="Refreshes every 60s">
 <div className="space-y-2">
 {[0, 1, 2].map((i) => (
 <SkeletonBlock key={i} className="h-8 w-full" />
 ))}
 </div>
 </Panel>
 );
 }

 // ─── ERROR ───
 if (isError) {
 return (
 <Panel title={title}>
 <EmptyState
 icon="alert-circle"
 title="Failed to load bottlenecks"
 action={
 <Button variant="secondary" onClick={() => refetch()}>
 Retry
 </Button>
 }
 />
 </Panel>
 );
 }

 const groups = (data?.groups ?? []).filter((g): g is ChainBottleneckGroup => g.count > 0);
 const automation = data?.automation;
 const automationNeedsAttention = automation?.status === 'attention' || automation?.status === 'unavailable';

 // ─── EMPTY (nothing stuck — good news) ───
 if (groups.length === 0) {
 if (hideWhenEmpty && !automationNeedsAttention) return null;
 return (
 <Panel title={title} meta="Refreshes every 60s">
 {automationNeedsAttention ? (
 <AutomationStatus summary={automation} />
 ) : (
 <EmptyState
 icon="inbox"
 title="No bottlenecks"
 description="Every chain step is moving within its SLA."
 />
 )}
 </Panel>
 );
 }

 // ─── DATA ───
 const total = data?.total ?? 0;
 return (
 <Panel
 title={title}
 meta={`${total} stuck`}
 bodyClassName="p-0"
 >
 <ul>
 {groups.map((g) => (
 <li
 key={g.key}
 className="flex items-center justify-between px-4 py-2.5 border-b border-subtle last:border-b-0"
 >
 <div className="min-w-0 flex-1">
 <div className="text-sm text-primary truncate">{g.label}</div>
 <div className="text-xs text-muted truncate">
 {g.rows
 .slice(0, 3)
 .map((r: ChainBottleneckRow) => r.doc_number)
 .join(', ')}
 {g.rows.length > 3 ? ` +${g.rows.length - 3} more` : ''}
 </div>
 </div>
 <div className="flex items-center gap-2 ml-3">
 <Chip variant={g.count >= 5 ? 'danger' : 'warning'}>
 <span className="font-mono tabular-nums">{g.count}</span>
 </Chip>
 <Link
 to={destinationFor(g.rows[0])}
 className="text-xs text-accent hover:underline"
 >
 View
 </Link>
 </div>
 </li>
 ))}
 </ul>
 {automation && <AutomationStatus summary={automation} />}
 </Panel>
 );
}

function AutomationStatus({ summary }: { summary?: ChainAutomationSummary }) {
 if (!summary) return null;

 const attention = summary.status === 'attention';
 const unavailable = summary.status === 'unavailable';
 const chip = unavailable ? 'neutral' : attention ? 'danger' : 'success';
 const label = unavailable ? 'Unavailable' : attention ? 'Needs attention' : 'Healthy';
 const outcomes = summary.listeners.outcomes;

 return (
 <div className="border-t border-default px-4 py-3 space-y-2" data-testid="chain-automation-status">
 <div className="flex items-center justify-between gap-3">
 <div>
 <div className="text-sm text-primary">Automation health</div>
 <div className="text-xs text-muted">Outbox, queued listeners, failed jobs, and supplier dispatch</div>
 </div>
 <Chip variant={chip}>{label}</Chip>
 </div>
 <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-muted">
 <div>
 Outbox: <span className="text-primary">{summary.outbox.pending} pending</span>
 {summary.outbox.stale_pending > 0 && <span className="text-danger"> · {summary.outbox.stale_pending} stale</span>}
 {summary.outbox.failed > 0 && <span className="text-danger"> · {summary.outbox.failed} failed</span>}
 </div>
 <div>
 Queue: <span className="text-primary">{summary.failed_jobs.total} failed jobs</span>
 </div>
 <div>
 Listeners: <span className="text-primary">{summary.listeners.processing} active</span>
 {summary.listeners.retrying > 0 && <span className="text-warning-fg"> · {summary.listeners.retrying} retrying</span>}
 {summary.listeners.failed > 0 && <span className="text-danger"> · {summary.listeners.failed} failed</span>}
 {outcomes && outcomes.failed > 0 && <span className="text-danger"> · {outcomes.failed} business failures</span>}
 {outcomes && outcomes.manual_required > 0 && <span className="text-warning-fg"> · {outcomes.manual_required} manual handoff{outcomes.manual_required === 1 ? '' : 's'}</span>}
 {outcomes && outcomes.skipped > 0 && <span className="text-muted"> · {outcomes.skipped} safely skipped</span>}
 </div>
 {summary.supplier_dispatch && (
 <div className="sm:col-span-2">
 Supplier dispatch: <span className="text-primary">{summary.supplier_dispatch.confirmed} confirmed</span>
 {summary.supplier_dispatch.portal_available > 0 && <span className="text-warning-fg"> · {summary.supplier_dispatch.portal_available} awaiting send confirmation</span>}
 {summary.supplier_dispatch.manual_required > 0 && <span className="text-warning-fg"> · {summary.supplier_dispatch.manual_required} manual</span>}
 {summary.supplier_dispatch.failed > 0 && <span className="text-danger"> · {summary.supplier_dispatch.failed} failed</span>}
 {summary.supplier_dispatch.stale_pending > 0 && <span className="text-danger"> · {summary.supplier_dispatch.stale_pending} stale</span>}
 </div>
 )}
 </div>
 {attention && (
 <p className="text-xs text-danger">
 Review failed queue jobs and business handoffs; retry outbox publication with <code>outbox:dispatch --retry-failed</code>, retry failed listener jobs from the queue worker, run <code>supplier:dispatch-recover --retry-failed</code> only after reviewing provider errors, and resolve supplier dispatch rows awaiting confirmation.
 </p>
 )}
 {unavailable && (
 <p className="text-xs text-muted">The automation ledger is unavailable, so chain completion cannot be fully verified.</p>
 )}
 </div>
 );
}

/**
 * Resolve the destination for the "View" link given the first row in the
 * group. Each entity type has its own list page.
 */
function destinationFor(row: ChainBottleneckRow | undefined): string {
 if (!row) return '#';
 switch (row.entity_type) {
 case 'sales_order': return `/crm/sales-orders/${row.entity_id}`;
 case 'work_order': return `/production/work-orders/${row.entity_id}`;
 case 'inspection': return `/quality/inspections/${row.entity_id}`;
 case 'delivery': return `/supply-chain/deliveries/${row.entity_id}`;
 case 'invoice': return `/accounting/invoices/${row.entity_id}`;
 case 'purchase_request': return `/purchasing/purchase-requests/${row.entity_id}`;
 case 'bill': return `/accounting/bills/${row.entity_id}`;
 case 'stock_movement': return `/inventory/stock-levels?view=movements&movement_id=${row.entity_id}`;
 case 'return_request': return `/return-management/${row.entity_id}`;
 case 'customer_complaint': return `/crm/complaints/${row.entity_id}`;
 default: return '#';
 }
}
