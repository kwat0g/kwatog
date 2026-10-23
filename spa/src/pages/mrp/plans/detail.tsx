import { useParams, Link } from 'react-router-dom';
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuRefreshCw } from '@/lib/icons';
import toast from 'react-hot-toast';
import { FulfillmentDialog } from '@/pages/crm/sales-orders/FulfillmentDialog';
import { mrpPlansApi } from '@/api/mrp/mrpPlans';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { productionDateTime } from '@/components/production/scheduleDates';
import { formatPeso } from '@/lib/formatNumber';
import { reportMutationError } from '@/lib/formErrors';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import type {
  MrpMaterialDiagnostic,
  MrpPlanDiagnostic,
  MrpPlanWarningDiagnostic,
} from '@/types/mrp';

const isWarningDiagnostic = (row: MrpPlanDiagnostic): row is MrpPlanWarningDiagnostic =>
  'kind' in row && row.kind === 'warning';

export default function MrpPlanDetailPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [reviewFulfillment, setReviewFulfillment] = useState(false);

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
      toast.success(
        plan.rerun_outcome === 'unchanged'
          ? 'MRP is already up to date. No plan records changed.'
          : `MRP updated — new version v${plan.version}.`,
      );
    },
    onError: (error) =>
      reportMutationError(
        error,
        'Could not re-run MRP. Review the run history for recovery details.',
      ),
  });

  const summary = useMemo(() => {
    if (!data) return null;
    const d = data.diagnostics.filter(
      (row): row is MrpMaterialDiagnostic => !isWarningDiagnostic(row),
    );
    return {
      totalDemand: d.reduce((s, r) => s + r.gross, 0),
      shortageCount: d.filter((r) => r.net > 0).length,
      legacySafetyStockDiagnostics: d.some((r) => r.safety_stock_gap === undefined),
      autoPrCount: data.auto_pr_count ?? 0,
      draftAutoPrCount:
        data.purchase_requests?.filter(
          (pr) => pr.is_auto_generated && pr.status === 'draft',
        ).length ?? 0,
      plannedCost: data.cost_summary?.planned_production_cost ?? null,
    };
  }, [data]);

  if (isLoading)
    return (
      <div>
        <PageHeader title="MRP plan" backTo="/mrp/plans" backLabel="Plans" />
        <SkeletonDetail />
      </div>
    );
  if (isError || !data)
    return (
      <div>
        <PageHeader title="MRP plan" backTo="/mrp/plans" backLabel="Plans" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load plan"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      </div>
    );

  return (
    <div>
      <PageHeader
        title={
          <div className="flex items-center gap-3">
            <span className="font-mono">{data.mrp_plan_no}</span>
            <Chip
              variant={
                data.status === 'active'
                  ? 'success'
                  : data.status === 'cancelled'
                    ? 'danger'
                    : 'neutral'
              }
            >
              v{data.version} · {data.status_label ?? data.status}
            </Chip>
          </div>
        }
        subtitle={data.sales_order ? `for ${data.sales_order.so_number}` : undefined}
        backTo="/mrp/plans"
        backLabel="Plans"
        actions={<div className="flex gap-2">{data.sales_order && data.sales_order.status === 'confirmed' && (can('production.wo.confirm') || can('production.schedule.confirm')) && <Button variant="secondary" onClick={() => setReviewFulfillment(true)}>Review fulfillment</Button>}{
          can('mrp.plans.run') ? (
            <Button
              variant="primary"
              size="sm"
              icon={<LuRefreshCw size={14} />}
              onClick={() => rerun.mutate()}
              loading={rerun.isPending}
            >
              Re-run
            </Button>
          ) : null
        }</div>}
      />

      {reviewFulfillment && data.sales_order && <FulfillmentDialog orderId={data.sales_order.id} onClose={() => setReviewFulfillment(false)} />}
      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-4">
          {data.status === 'active' && summary?.legacySafetyStockDiagnostics && (
            <div
              role="status"
              className="rounded-md border border-warning bg-warning-bg px-3 py-2 text-sm text-warning-fg"
            >
              This plan predates separate safety-stock warnings. Re-run MRP to refresh its shortage
              and purchase recommendations.
              {summary.draftAutoPrCount > 0 &&
                ' Re-running can cancel draft auto-PRs if no production shortage remains.'}
            </div>
          )}
          {/* Summary cards */}
          {summary && (
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <div className="rounded-md border border-default bg-canvas p-3">
                <div className="text-2xs uppercase tracking-wider text-muted mb-1">
                  Total demand
                </div>
                <div className="text-lg font-mono tabular-nums font-medium">
                  {summary.totalDemand.toFixed(0)}
                </div>
                <div className="text-2xs text-muted mt-0.5">units gross</div>
              </div>
              <div
                className={`rounded-md border p-3 ${summary.shortageCount > 0 ? 'border-danger bg-danger-bg' : 'border-default bg-canvas'}`}
              >
                <div className="text-2xs uppercase tracking-wider text-muted mb-1">
                  {summary.legacySafetyStockDiagnostics ? 'Shortages' : 'Production shortages'}
                </div>
                <div
                  className={`text-lg font-mono tabular-nums font-medium ${summary.shortageCount > 0 ? 'text-danger-fg' : ''}`}
                >
                  {summary.shortageCount}
                </div>
                <div className="text-2xs text-muted mt-0.5">
                  {summary.legacySafetyStockDiagnostics
                    ? 'saved plan calculation'
                    : 'production demand uncovered'}
                </div>
              </div>
              <div
                className={`rounded-md border p-3 ${summary.autoPrCount > 0 ? 'border-info bg-info-bg' : 'border-default bg-canvas'}`}
              >
                <div className="text-2xs uppercase tracking-wider text-muted mb-1">Auto PRs</div>
                <div className="text-lg font-mono tabular-nums font-medium">
                  {summary.autoPrCount}
                </div>
                <div className="text-2xs text-muted mt-0.5">generated</div>
              </div>
              <div
                className={`rounded-md border p-3 ${summary.plannedCost ? 'border-success bg-success-bg' : 'border-default bg-canvas'}`}
              >
                <div className="text-2xs uppercase tracking-wider text-muted mb-1">
                  Planned cost
                </div>
                <div className="text-lg font-mono tabular-nums font-medium">
                  {formatPeso(summary.plannedCost)}
                </div>
                <div className="text-2xs text-muted mt-0.5">remaining production</div>
              </div>
            </div>
          )}
          {data.cost_summary && (
            <Panel title="Cost estimate">
              <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                  <div className="text-2xs uppercase text-muted">Material</div>
                  <div className="font-mono mt-1">
                    {formatPeso(data.cost_summary.material_cost)}
                  </div>
                </div>
                <div>
                  <div className="text-2xs uppercase text-muted">Labor</div>
                  <div className="font-mono mt-1">{formatPeso(data.cost_summary.labor_cost)}</div>
                </div>
                <div>
                  <div className="text-2xs uppercase text-muted">Machine</div>
                  <div className="font-mono mt-1">{formatPeso(data.cost_summary.machine_cost)}</div>
                </div>
                <div>
                  <div className="text-2xs uppercase text-muted">Overhead</div>
                  <div className="font-mono mt-1">
                    {formatPeso(data.cost_summary.overhead_cost)}
                  </div>
                </div>
              </div>
            </Panel>
          )}
          <Panel title="Diagnostics" meta={`${data.diagnostics.length} materials`} noPadding>
            {data.diagnostics.length === 0 ? (
              <div className="p-4 text-sm text-muted">No materials evaluated (no active BOM).</div>
            ) : (
              <div className="overflow-x-auto">
                <table className={tableCls}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th>Item</Th>
                      <Th align="right">Gross</Th>
                      <Th align="right">On hand</Th>
                      <Th align="right">Reserved</Th>
                      <Th align="right">QC held</Th>
                      <Th align="right">Usable now</Th>
                      <Th align="right">In transit</Th>
                      <Th align="right">Unit cost</Th>
                      <Th align="right">Shortfall</Th>
                      <Th align="right">Shortfall cost</Th>
                      <Th>Action</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.diagnostics.map((d, index) =>
                      isWarningDiagnostic(d) ? (
                        <tr key={`warning-${d.sales_order_line_id}-${index}`} className={trCls}>
                          <Td colSpan={11}>
                            <div className="text-warning-fg">{d.message}</div>
                          </Td>
                        </tr>
                      ) : (
                        <tr key={d.item_id} className={trCls}>
                          <Td mono>{d.item_code}</Td>
                          <Td align="right" mono>
                            {d.gross.toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {d.on_hand.toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {d.reserved.toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {(d.quality_held ?? 0).toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {d.usable == null ? 'Not recorded' : d.usable.toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {d.in_transit.toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(d.standard_unit_cost)}
                          </Td>
                          <Td align="right" mono className="font-medium">
                            {d.net.toFixed(3)}
                          </Td>
                          <Td align="right" mono>
                            {formatPeso(d.net_cost)}
                          </Td>
                          <Td>
                            <div className="flex flex-wrap items-center gap-1">
                              {(d.safety_stock_gap ?? 0) > 0 && (
                                <Chip variant="warning">
                                  Safety stock gap ·{' '}
                                  <span className="font-mono tabular-nums">
                                    {d.safety_stock_gap?.toFixed(3)}
                                  </span>
                                </Chip>
                              )}
                              {d.action === 'pr_created' ? (
                                <Chip variant={d.priority === 'urgent' ? 'danger' : 'info'}>
                                  PR · {d.priority}
                                </Chip>
                              ) : d.action === 'awaiting_qc' ? (
                                <Chip variant="warning">
                                  QC hold ·{' '}
                                  <span className="font-mono tabular-nums">
                                    {d.awaiting_qc?.toFixed(3)}
                                  </span>
                                </Chip>
                              ) : d.action === 'awaiting_po_approval' ? (
                                <Chip variant="info">
                                  PO pending ·{' '}
                                  <span className="font-mono tabular-nums">
                                    {d.pending_purchase_orders?.toFixed(3)}
                                  </span>
                                </Chip>
                              ) : d.action === 'safety_stock_warning' ? null : (
                                <Chip variant="success">sufficient</Chip>
                              )}
                            </div>
                          </Td>
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="Linked records">
            <div className="space-y-3 text-sm">
              <div>
                <div className="text-2xs uppercase tracking-wider text-muted mb-1">
                  Work orders ({data.work_orders?.length ?? 0})
                </div>
                {data.work_orders?.length ? (
                  data.work_orders.map((w) => (
                    <Link
                      key={w.id}
                      to={`/production/work-orders/${w.id}`}
                      className={`block font-mono text-xs text-accent hover:underline ${w.parent ? 'pl-4' : ''}`}
                    >
                      {w.parent ? '↳ ' : ''}
                      {w.wo_number}{' '}
                      <span className="text-muted">
                        ({w.status_label ?? w.status}, qty {w.quantity_target})
                      </span>
                      <span className="mt-1 block font-sans text-muted">
                        {w.schedule
                          ? `Production: ${productionDateTime(w.schedule.scheduled_start)} – ${productionDateTime(w.schedule.scheduled_end)}`
                          : 'Choose production day when confirming'}
                        {w.planned_end && ` · Due ${productionDateTime(w.planned_end)}`}
                      </span>
                    </Link>
                  ))
                ) : (
                  <span className="text-muted">—</span>
                )}
              </div>
              <div>
                <div className="text-2xs uppercase tracking-wider text-muted mb-1">
                  Purchase requests ({data.purchase_requests?.length ?? 0})
                </div>
                {data.purchase_requests?.length ? (
                  data.purchase_requests.map((p) => (
                    <div key={p.id}>
                      <Link
                        to={`/purchasing/purchase-requests/${p.id}`}
                        className="block font-mono text-xs text-accent hover:underline"
                      >
                        {p.pr_number}{' '}
                        <span className="text-muted">
                          ({p.status_label ?? p.status} · {p.priority_label ?? p.priority})
                        </span>
                      </Link>
                      {p.purchase_orders?.map((po) => (
                        <Link
                          key={po.id}
                          to={`/purchasing/purchase-orders/${po.id}`}
                          className="block pl-4 font-mono text-xs text-accent hover:underline"
                        >
                          ↳ {po.po_number}{' '}
                          <span className="text-muted">({po.status_label ?? po.status})</span>
                        </Link>
                      ))}
                    </div>
                  ))
                ) : (
                  <span className="text-muted">—</span>
                )}
              </div>
            </div>
          </Panel>
        </div>
      </div>
    </div>
  );
}
