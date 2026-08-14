import { useParams, Link } from 'react-router-dom';
import { useMemo } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuRefreshCw } from '@/lib/icons';
import toast from 'react-hot-toast';
import { mrpPlansApi } from '@/api/mrp/mrpPlans';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import type { MrpMaterialDiagnostic, MrpPlanDiagnostic, MrpPlanWarningDiagnostic } from '@/types/mrp';

const isWarningDiagnostic = (row: MrpPlanDiagnostic): row is MrpPlanWarningDiagnostic =>
 'kind' in row && row.kind === 'warning';

export default function MrpPlanDetailPage() {
 const { id } = useParams<{ id: string }>();
 const qc = useQueryClient();
 const { can } = usePermission();

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['mrp', 'plans', 'detail', id],
 queryFn: () => mrpPlansApi.show(id!),
 enabled: !!id,
 });

 const rerun = useMutation({
 mutationFn: () => mrpPlansApi.rerun(id!),
 onSuccess: (plan) => {
 qc.invalidateQueries({ queryKey: ['mrp', 'plans'] });
 qc.setQueryData(['mrp', 'plans', 'detail', id], plan);
 toast.success(`Re-ran MRP — new version v${plan.version}.`);
 },
 });

 const summary = useMemo(() => {
 if (!data) return null;
 const d = data.diagnostics.filter((row): row is MrpMaterialDiagnostic => !isWarningDiagnostic(row));
 return {
 totalDemand: d.reduce((s, r) => s + r.gross, 0),
 shortageCount: d.filter((r) => r.net > 0).length,
 autoPrCount: data.auto_pr_count ?? 0,
 };
 }, [data]);

 if (isLoading) return <div><PageHeader title="MRP plan" backTo="/mrp/plans" backLabel="Plans"
 breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'Plans', href: '/mrp/plans' }, { label: 'Loading…' }]} /><SkeletonDetail /></div>;
 if (isError || !data) return (
 <div>
 <PageHeader title="MRP plan" backTo="/mrp/plans" backLabel="Plans"
 breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'Plans', href: '/mrp/plans' }, { label: 'Error' }]} />
 <EmptyState icon="alert-circle" title="Failed to load plan"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 </div>
 );

 return (
 <div>
 <PageHeader
 title={
 <div className="flex items-center gap-3">
 <span className="font-mono">{data.mrp_plan_no}</span>
 <Chip variant={data.status === 'active' ? 'success' : data.status === 'cancelled' ? 'danger' : 'neutral'}>
 v{data.version} · {data.status_label ?? data.status}
 </Chip>
 </div>
 }
 subtitle={data.sales_order ? `for ${data.sales_order.so_number}` : undefined}
 backTo="/mrp/plans"
 backLabel="Plans"
 breadcrumbs={[{ label: 'MRP', href: '/mrp' }, { label: 'Plans', href: '/mrp/plans' }, { label: data.mrp_plan_no }]}
 actions={can('mrp.plans.run') ? (
 <Button variant="primary" size="sm" icon={<LuRefreshCw size={14} />}
 onClick={() => rerun.mutate()} loading={rerun.isPending}>
 Re-run
 </Button>
 ) : null}
 />

 <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
 <div className="lg:col-span-2 space-y-4">
 {/* Summary cards */}
 {summary && (
 <div className="grid grid-cols-3 gap-3">
 <div className="rounded-md border border-default bg-canvas p-3">
 <div className="text-2xs uppercase tracking-wider text-muted mb-1">Total demand</div>
 <div className="text-lg font-mono tabular-nums font-medium">{summary.totalDemand.toFixed(0)}</div>
 <div className="text-2xs text-muted mt-0.5">units gross</div>
 </div>
 <div className={`rounded-md border p-3 ${summary.shortageCount > 0 ? 'border-danger/30 bg-danger-bg/5' : 'border-default bg-canvas'}`}>
 <div className="text-2xs uppercase tracking-wider text-muted mb-1">Shortages</div>
 <div className={`text-lg font-mono tabular-nums font-medium ${summary.shortageCount > 0 ? 'text-danger-fg' : ''}`}>
 {summary.shortageCount}
 </div>
 <div className="text-2xs text-muted mt-0.5">materials short</div>
 </div>
 <div className={`rounded-md border p-3 ${summary.autoPrCount > 0 ? 'border-info/30 bg-info-bg/5' : 'border-default bg-canvas'}`}>
 <div className="text-2xs uppercase tracking-wider text-muted mb-1">Auto PRs</div>
 <div className="text-lg font-mono tabular-nums font-medium">{summary.autoPrCount}</div>
 <div className="text-2xs text-muted mt-0.5">generated</div>
 </div>
 </div>
 )}
 <Panel title="Diagnostics" meta={`${data.diagnostics.length} materials`} noPadding>
 {data.diagnostics.length === 0 ? (
 <div className="p-4 text-sm text-muted">No materials evaluated (no active BOM).</div>
 ) : (
 <table className={tableCls}>
 <thead>
 <tr className={theadTrCls}>
 <Th>Item</Th>
 <Th align="right">Gross</Th>
 <Th align="right">On hand</Th>
 <Th align="right">Reserved</Th>
 <Th align="right">In transit</Th>
 <Th align="right">Net</Th>
 <Th>Action</Th>
 </tr>
 </thead>
 <tbody>
 {data.diagnostics.map((d, index) => (
 isWarningDiagnostic(d) ? (
 <tr key={`warning-${d.sales_order_line_id}-${index}`} className={trCls}>
 <Td colSpan={7}>
 <div className="text-warning-fg">{d.message}</div>
 </Td>
 </tr>
 ) : (
 <tr key={d.item_id} className={trCls}>
 <Td mono>{d.item_code}</Td>
 <Td align="right" mono>{d.gross.toFixed(3)}</Td>
 <Td align="right" mono>{d.on_hand.toFixed(3)}</Td>
 <Td align="right" mono>{d.reserved.toFixed(3)}</Td>
 <Td align="right" mono>{d.in_transit.toFixed(3)}</Td>
 <Td align="right" mono className="font-medium">{d.net.toFixed(3)}</Td>
 <Td>
 {d.action === 'pr_created'
 ? <Chip variant={d.priority === 'urgent' ? 'danger' : 'info'}>PR · {d.priority}</Chip>
 : <Chip variant="success">sufficient</Chip>}
 </Td>
 </tr>
 )
 ))}
 </tbody>
 </table>
 )}
 </Panel>
 </div>

 <div className="space-y-4">
 <Panel title="Linked records">
 <div className="space-y-3 text-sm">
 <div>
 <div className="text-2xs uppercase tracking-wider text-muted mb-1">Work orders ({data.draft_wo_count})</div>
 {data.work_orders?.length ? data.work_orders.map((w) => (
 <Link key={w.id} to={`/production/work-orders/${w.id}`} className="block font-mono text-xs text-accent hover:underline">
 {w.wo_number} <span className="text-muted">({w.status_label ?? w.status}, qty {w.quantity_target})</span>
 </Link>
 )) : <span className="text-muted">—</span>}
 </div>
 <div>
 <div className="text-2xs uppercase tracking-wider text-muted mb-1">Auto PRs ({data.auto_pr_count})</div>
 {data.purchase_requests?.length ? data.purchase_requests.map((p) => (
 <Link key={p.id} to={`/purchasing/purchase-requests/${p.id}`} className="block font-mono text-xs text-accent hover:underline">
 {p.pr_number} <span className="text-muted">({p.status_label ?? p.status} · {p.priority_label ?? p.priority})</span>
 </Link>
 )) : <span className="text-muted">—</span>}
 </div>
 </div>
 </Panel>
 </div>
 </div>
 </div>
 );
}
