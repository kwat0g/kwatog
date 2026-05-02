import { useParams, Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import { mrpPlansApi } from '@/api/mrp/mrpPlans';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';

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

  if (isLoading) return <div><PageHeader title="MRP plan" backTo="/mrp/plans" backLabel="Plans" /><SkeletonDetail /></div>;
  if (isError || !data) return (
    <div>
      <PageHeader title="MRP plan" backTo="/mrp/plans" backLabel="Plans" />
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
              v{data.version} · {data.status}
            </Chip>
          </div>
        }
        subtitle={data.sales_order ? `for ${data.sales_order.so_number}` : undefined}
        backTo="/mrp/plans"
        backLabel="Plans"
        actions={can('mrp.plans.run') ? (
          <Button variant="primary" size="sm" icon={<RefreshCw size={14} />}
            onClick={() => rerun.mutate()} loading={rerun.isPending}>
            Re-run
          </Button>
        ) : null}
      />

      <div className="px-5 py-4 grid gap-4 lg:grid-cols-3">
        <div className="lg:col-span-2 space-y-4">
          <Panel title="Diagnostics" meta={`${data.diagnostics.length} materials`} noPadding>
            {data.diagnostics.length === 0 ? (
              <div className="p-4 text-sm text-muted">No materials evaluated (no active BOM).</div>
            ) : (
              <table className="w-full text-xs">
                <thead className="bg-subtle">
                  <tr>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Item</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Gross</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">On hand</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Reserved</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">In transit</th>
                    <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Net</th>
                    <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {data.diagnostics.map((d) => (
                    <tr key={d.item_id} className="border-t border-subtle hover:bg-subtle">
                      <td className="px-2.5 py-2 font-mono">{d.item_code}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{d.gross.toFixed(3)}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{d.on_hand.toFixed(3)}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{d.reserved.toFixed(3)}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums">{d.in_transit.toFixed(3)}</td>
                      <td className="px-2.5 py-2 text-right font-mono tabular-nums font-medium">{d.net.toFixed(3)}</td>
                      <td className="px-2.5 py-2">
                        {d.action === 'pr_created'
                          ? <Chip variant={d.priority === 'urgent' ? 'danger' : 'info'}>PR · {d.priority}</Chip>
                          : <Chip variant="success">sufficient</Chip>}
                      </td>
                    </tr>
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
                    {w.wo_number} <span className="text-muted">({w.status}, qty {w.quantity_target})</span>
                  </Link>
                )) : <span className="text-muted">—</span>}
              </div>
              <div>
                <div className="text-2xs uppercase tracking-wider text-muted mb-1">Auto PRs ({data.auto_pr_count})</div>
                {data.purchase_requests?.length ? data.purchase_requests.map((p) => (
                  <Link key={p.id} to={`/purchasing/purchase-requests/${p.id}`} className="block font-mono text-xs text-accent hover:underline">
                    {p.pr_number} <span className="text-muted">({p.status} · {p.priority})</span>
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
