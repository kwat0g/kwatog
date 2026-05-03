/**
 * Sprint 7 — Task 64 — Quality dashboard.
 *
 * KPIs: pass rate (last 30 days), open NCRs, defect Pareto. Bar chart
 * uses indigo bars (matches DESIGN-SYSTEM.md spec) with cumulative %
 * overlay implied by the table. Click a bar to drill into the
 * inspections containing that defect.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { analyticsApi, type ParetoDrillRow } from '@/api/quality/analytics';
import { ncrsApi } from '@/api/quality/ncrs';
import { inspectionsApi } from '@/api/quality/inspections';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';

export default function QualityDashboardPage() {
  const [selectedDefect, setSelectedDefect] = useState<string | null>(null);

  const pareto = useQuery({
    queryKey: ['quality', 'pareto', 'last30'],
    queryFn: () => analyticsApi.defectPareto({ limit: 10 }),
  });

  // Pass-rate KPI: aggregate from inspections list (last 30 days).
  const passRate = useQuery({
    queryKey: ['quality', 'pass-rate'],
    queryFn: async () => {
      const res = await inspectionsApi.list({ per_page: 100 });
      const completed = res.data.filter((i) => i.status === 'passed' || i.status === 'failed');
      if (completed.length === 0) return { rate: 0, sample: 0 };
      const passed = completed.filter((i) => i.status === 'passed').length;
      return { rate: (passed / completed.length) * 100, sample: completed.length };
    },
  });

  const openNcrs = useQuery({
    queryKey: ['quality', 'ncrs', 'open'],
    queryFn: () => ncrsApi.list({ status: 'open', per_page: 5 }),
  });

  const drillDown = useQuery({
    queryKey: ['quality', 'pareto', 'drill', selectedDefect],
    queryFn: () => analyticsApi.paretoDrillDown(selectedDefect ?? ''),
    enabled: Boolean(selectedDefect),
  });

  const maxCount = pareto.data?.rows[0]?.defect_count ?? 1;

  return (
    <div>
      <PageHeader title="Quality dashboard" subtitle="Last 30 days" />

      <div className="px-5 grid grid-cols-3 gap-4 mb-4">
        <StatCard
          label="Pass rate"
          value={passRate.isLoading ? '—' : `${passRate.data?.rate.toFixed(1) ?? 0}%`}
          helper={passRate.data ? `${passRate.data.sample} inspections` : 'loading…'}
        />
        <StatCard
          label="Open NCRs"
          value={openNcrs.isLoading ? '—' : String(openNcrs.data?.meta.total ?? 0)}
          helper="awaiting disposition"
        />
        <StatCard
          label="Total defects"
          value={pareto.isLoading ? '—' : String(pareto.data?.total_defects ?? 0)}
          helper="across top 10 parameters"
        />
      </div>

      <div className="px-5 grid grid-cols-3 gap-4">
        <Panel title="Defect Pareto" meta="Top 10 parameters" className="col-span-2">
          {pareto.isLoading && <SkeletonBlock className="h-64" />}
          {pareto.isError && (
            <EmptyState
              icon="alert-circle"
              title="Failed to load defect data"
              action={<Button variant="secondary" onClick={() => pareto.refetch()}>Retry</Button>}
            />
          )}
          {pareto.data && pareto.data.rows.length === 0 && (
            <EmptyState icon="check-circle" title="No defects in the period" description="All inspections passed in the last 30 days." />
          )}
          {pareto.data && pareto.data.rows.length > 0 && (
            <div className="space-y-2">
              {pareto.data.rows.map((row) => (
                <button
                  key={row.parameter_name}
                  onClick={() => setSelectedDefect(row.parameter_name)}
                  className={`w-full text-left p-2 rounded-md hover:bg-subtle transition-colors ${
                    selectedDefect === row.parameter_name ? 'bg-subtle' : ''
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2 mb-1">
                        <span className="text-sm truncate">{row.parameter_name}</span>
                        {row.is_critical && <Chip variant="danger">Critical</Chip>}
                      </div>
                      <div className="h-3 bg-subtle rounded-md overflow-hidden">
                        <div
                          className="h-full bg-accent transition-all"
                          style={{ width: `${(row.defect_count / maxCount) * 100}%` }}
                        />
                      </div>
                    </div>
                    <div className="text-right font-mono tabular-nums text-xs whitespace-nowrap">
                      <div className="font-medium">{row.defect_count}</div>
                      <div className="text-muted">{row.percentage.toFixed(1)}% · cum {row.cumulative_percentage.toFixed(1)}%</div>
                    </div>
                  </div>
                </button>
              ))}
            </div>
          )}
        </Panel>

        <div className="space-y-4">
          <Panel title="Open NCRs" meta={`${openNcrs.data?.meta.total ?? 0} total`} noPadding>
            {openNcrs.isLoading && <div className="px-4 py-4"><SkeletonBlock className="h-32" /></div>}
            {openNcrs.data && openNcrs.data.data.length === 0 && (
              <div className="px-4 py-4 text-sm text-muted">No open NCRs.</div>
            )}
            {openNcrs.data && openNcrs.data.data.length > 0 && (
              <ul className="divide-y divide-default">
                {openNcrs.data.data.map((n) => (
                  <li key={n.id} className="px-4 py-2.5">
                    <Link to={`/quality/ncrs/${n.id}`} className="block hover:bg-subtle rounded-md -mx-1 px-1">
                      <div className="flex items-center gap-2 mb-0.5">
                        <span className="font-mono text-xs text-accent">{n.ncr_number}</span>
                        <Chip variant={n.severity === 'critical' ? 'danger' : n.severity === 'high' ? 'warning' : 'info'}>
                          {n.severity}
                        </Chip>
                      </div>
                      <div className="text-xs text-muted truncate">{n.defect_description}</div>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
            <div className="px-4 py-2 border-t border-default">
              <Link to="/quality/ncrs" className="text-xs text-accent hover:underline">View all NCRs →</Link>
            </div>
          </Panel>

          {selectedDefect && (
            <Panel
              title={`Inspections — ${selectedDefect}`}
              meta={`${drillDown.data?.length ?? 0} found`}
              noPadding
            >
              {drillDown.isLoading && <div className="px-4 py-4"><SkeletonBlock className="h-32" /></div>}
              {drillDown.data && drillDown.data.length === 0 && (
                <div className="px-4 py-4 text-sm text-muted">No inspections found.</div>
              )}
              {drillDown.data && drillDown.data.length > 0 && (
                <ul className="divide-y divide-default max-h-72 overflow-y-auto">
                  {drillDown.data.slice(0, 20).map((row: ParetoDrillRow) => (
                    <li key={row.id} className="px-4 py-2 text-xs">
                      <Link to={`/quality/inspections/${row.id}`} className="block hover:bg-subtle rounded-md -mx-1 px-1">
                        <div className="font-mono text-accent">{row.inspection_number}</div>
                        <div className="text-muted">
                          {row.product?.part_number ?? '—'} · {row.stage} · {row.completed_at?.slice(0, 10)}
                        </div>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          )}
        </div>
      </div>
    </div>
  );
}
