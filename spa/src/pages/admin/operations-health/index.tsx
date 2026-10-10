import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { rolloutHealthApi } from '@/api/rolloutHealth';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';

export default function OperationsHealthPage() {
  const { can } = usePermission();
  const query = useQuery({
    queryKey: ['rollout-health'],
    queryFn: rolloutHealthApi.get,
    refetchInterval: 60_000,
  });

  if (query.isLoading) return <SkeletonTable rows={7} columns={4} />;
  if (query.isError || !query.data)
    return (
      <EmptyState
        icon="alert-circle"
        title="Could not load operational health"
        action={<Button onClick={() => query.refetch()}>Retry</Button>}
      />
    );
  const health = query.data;

  return (
    <div>
      <PageHeader
        title="Operations Health"
        subtitle={`Rollout readiness and workflow telemetry · ${formatDateTime(health.generated_at)}`}
        actions={
          <Chip variant={health.status === 'healthy' ? 'success' : 'warning'}>
            {health.status_label ?? health.status}
          </Chip>
        }
        refreshingQueryKey={['rollout-health']}
      />
      <div className="p-5 space-y-4">
        {health.automation && (
          <Panel title="Pricing automation">
            <div className="space-y-2 text-sm">
              <p>
                Scheduler: {health.automation.scheduler_status} ·{' '}
                {health.automation.scheduler_last_tick
                  ? formatDateTime(health.automation.scheduler_last_tick)
                  : 'No heartbeat'}
              </p>
              <p>
                Pricing refreshes: {health.automation.pending_pricing} pending ·{' '}
                {health.automation.failed_pricing} failed
              </p>
              <p>
                Refreshes overdue 15 minutes: {health.automation_issues?.overdue_pricing ?? 0} ·
                Active BOMs awaiting costing: {health.automation_issues?.stale_boms ?? 0}
              </p>
              <p>
                Pricing requests: {health.automation.open_price_requests} open · Supplier cost
                reviews: {health.automation.pending_cost_reviews} pending
              </p>
              <div className="flex flex-wrap gap-3">
                {(can('crm.price_agreements.view') ||
                  can('crm.price_agreements.approve') ||
                  can('crm.price_agreements.propose')) && (
                  <Link className="text-accent underline" to="/crm/price-agreements/requests">
                    Review pricing requests
                  </Link>
                )}
                {can('mrp.boms.view') && (
                  <Link className="text-accent underline" to="/mrp/boms">
                    Review BOM costs
                  </Link>
                )}
              </div>
            </div>
          </Panel>
        )}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          <StatCard
            label="Quality-plan coverage"
            value={
              health.quality_plans.coverage_percent == null
                ? '—'
                : `${health.quality_plans.coverage_percent}%`
            }
            helper={`${health.quality_plans.covered_items}/${health.quality_plans.eligible_items} items`}
          />
          <StatCard
            label="Missed QC triggers"
            value={health.qc_triggers.pending_grns_without_inspection}
            helper={`after ${health.qc_triggers.grace_minutes} min`}
          />
          <StatCard
            label="Scanner recognition"
            value={
              health.scanner.recognition_rate == null ? '—' : `${health.scanner.recognition_rate}%`
            }
            helper={`${health.scanner.scans_24h} scans / 24h`}
          />
          <StatCard
            label="Overdue actions"
            value={health.actions.overdue}
            helper={`${health.actions.unassigned} unassigned`}
          />
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <Panel title="Items missing quality plans">
            {health.quality_plans.missing.length === 0 ? (
              <p className="text-sm text-success-fg">All eligible items are covered.</p>
            ) : (
              <div className="divide-y divide-subtle">
                {health.quality_plans.missing.map((item) => (
                  <div key={item.id} className="py-2 flex items-center gap-2">
                    <Link
                      className="font-mono text-sm text-accent hover:underline"
                      to={`/inventory/items/${item.id}/quality-plans`}
                    >
                      {item.code}
                    </Link>
                    <span className="text-xs flex-1">{item.name}</span>
                    {item.is_critical && <Chip variant="danger">critical</Chip>}
                  </div>
                ))}
              </div>
            )}
          </Panel>
          <Panel title="Scanner exceptions · 24h">
            {health.scanner.top_unrecognized.length === 0 ? (
              <p className="text-sm text-success-fg">No unrecognized barcodes.</p>
            ) : (
              <div className="divide-y divide-subtle">
                {health.scanner.top_unrecognized.map((scan) => (
                  <div key={scan.barcode} className="py-2 flex justify-between text-sm">
                    <span className="font-mono">{scan.barcode}</span>
                    <Chip variant="warning">{scan.occurrences}×</Chip>
                  </div>
                ))}
              </div>
            )}
          </Panel>
        </div>
      </div>
    </div>
  );
}
