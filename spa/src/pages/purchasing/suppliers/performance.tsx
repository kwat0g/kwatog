import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import { supplierPerformanceApi } from '@/api/purchasing/supplier-performance';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { StatCard } from '@/components/ui/StatCard';
import { usePermission } from '@/hooks/usePermission';
import type { SupplierPerformance } from '@/types/supplierPerformance';

function fmtPct(v: string | null): string {
  return v === null ? '—' : `${Number(v).toFixed(1)}%`;
}
function fmtDays(v: string | null): string {
  if (v === null) return '—';
  const n = Number(v);
  return `${n >= 0 ? '+' : ''}${n.toFixed(1)} days`;
}
function scoreVariant(score: number | null): ChipVariant {
  if (score === null) return 'neutral';
  if (score >= 95) return 'success';
  if (score >= 85) return 'info';
  if (score >= 80) return 'warning';
  return 'danger';
}
function monthLabel(year: number, month: number): string {
  return new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
}

export default function SupplierPerformancePage() {
  const { id = '' } = useParams<{ id: string }>();
  const { can } = usePermission();
  const queryClient = useQueryClient();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'supplier-performance', id],
    queryFn: () => supplierPerformanceApi.show(id, 6),
    placeholderData: (prev) => prev,
  });

  const recompute = useMutation({
    mutationFn: () => supplierPerformanceApi.recompute(id),
    onSuccess: () => {
      toast.success('Performance recomputed.');
      queryClient.invalidateQueries({ queryKey: ['purchasing', 'supplier-performance', id] });
    },
    onError: () => toast.error('Failed to recompute.'),
  });

  const renderHeader = (d?: SupplierPerformance) => (
    <PageHeader
      title="Supplier performance"
      backTo="/accounting/vendors"
      backLabel="Vendors"
      subtitle={d ? d.vendor.name : undefined}
      actions={
        can('purchasing.suppliers.performance.recompute') && (
          <Button
            variant="secondary"
            size="sm"
            icon={<RefreshCw size={14} />}
            disabled={recompute.isPending}
            onClick={() => recompute.mutate()}
          >
            {recompute.isPending ? 'Recomputing…' : 'Recompute now'}
          </Button>
        )
      }
    />
  );

  if (isLoading && !data) {
    return (
      <div>
        {renderHeader()}
        <div className="px-5 py-4 grid grid-cols-1 md:grid-cols-5 gap-3">
          {[0, 1, 2, 3, 4].map((i) => (
            <div key={i} className="h-24 bg-elevated rounded-md animate-pulse" />
          ))}
        </div>
      </div>
    );
  }

  if (isError) {
    return (
      <div>
        {renderHeader()}
        <EmptyState
          icon="alert-circle"
          title="Failed to load performance"
          description="Something went wrong."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  if (!data || (!data.latest && data.trend.length === 0)) {
    return (
      <div>
        {renderHeader(data ?? undefined)}
        <EmptyState
          icon="bar-chart"
          title="No performance snapshots yet"
          description={
            'Snapshots are computed monthly. Click "Recompute now" to generate the current month, or wait for the next scheduled run.'
          }
          action={
            can('purchasing.suppliers.performance.recompute') ? (
              <Button variant="primary" onClick={() => recompute.mutate()}>
                Recompute now
              </Button>
            ) : undefined
          }
        />
      </div>
    );
  }

  const latest = data.latest;
  const score = latest?.overall_score ? Number(latest.overall_score) : null;
  const maxTrendScore = Math.max(
    100,
    ...data.trend
      .map((p) => (p.overall_score ? Number(p.overall_score) : 0))
      .filter((n) => Number.isFinite(n)),
  );

  return (
    <div>
      {renderHeader(data)}

      {/* KPI row */}
      <div className="px-5 py-4 grid grid-cols-1 md:grid-cols-5 gap-3">
        <StatCard
          label="Overall score"
          value={
            score === null ? (
              <span className="text-subtle">N/A</span>
            ) : (
              <span className="flex items-baseline gap-1">
                {score.toFixed(1)}
                <span className="text-xs text-muted">/ 100</span>
              </span>
            )
          }
          helper={
            latest
              ? `${monthLabel(latest.period_year, latest.period_month)} · ${latest.po_count} POs · ${latest.grn_count} GRNs`
              : undefined
          }
        />
        <StatCard
          label="On-time delivery"
          value={fmtPct(latest?.on_time_delivery_rate ?? null)}
          helper="Target ≥ 95%"
        />
        <StatCard
          label="Quality pass"
          value={fmtPct(latest?.quality_pass_rate ?? null)}
          helper="Target ≥ 98%"
        />
        <StatCard
          label="Price variance"
          value={fmtPct(latest?.price_variance_pct ?? null)}
          helper="Target ≤ 5%"
        />
        <StatCard
          label="Lead time variance"
          value={fmtDays(latest?.lead_time_variance_days ?? null)}
          helper="Target ≤ 2 days"
        />
      </div>

      {/* Score chip */}
      {score !== null && (
        <div className="px-5 pb-4">
          <Chip variant={scoreVariant(score)}>
            {score >= 95
              ? 'Excellent supplier'
              : score >= 85
                ? 'Good standing'
                : score >= 80
                  ? 'Needs attention'
                  : 'Underperforming — review required'}
          </Chip>
        </div>
      )}

      {/* Trend (simple bars) */}
      {data.trend.length > 1 && (
        <div className="px-5 py-4 border-t border-default">
          <h3 className="text-sm font-medium mb-3">6-month trend</h3>
          <div className="bg-surface border border-default rounded-md p-4">
            <div className="flex items-end gap-2 h-32">
              {data.trend.map((p) => {
                const v = p.overall_score === null ? 0 : Number(p.overall_score);
                const heightPct = maxTrendScore > 0 ? (v / maxTrendScore) * 100 : 0;
                const variant = scoreVariant(v);
                return (
                  <div
                    key={`${p.period_year}-${p.period_month}`}
                    className="flex flex-col items-center flex-1"
                  >
                    <div className="text-2xs font-mono tabular-nums text-muted mb-1">
                      {p.overall_score === null ? '—' : v.toFixed(0)}
                    </div>
                    <div
                      className="w-full rounded-t-sm transition-all"
                      style={{
                        height: `${heightPct}%`,
                        background: `var(--${variant === 'neutral' ? 'border-default' : variant + '-bg'})`,
                      }}
                      aria-label={`${monthLabel(p.period_year, p.period_month)} score ${v.toFixed(1)}`}
                    />
                    <div className="text-2xs text-muted mt-1.5">
                      {monthLabel(p.period_year, p.period_month)}
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
