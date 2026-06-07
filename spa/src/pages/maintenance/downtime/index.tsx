/** ADV8 — Downtime analytics dashboard. */
import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Activity, Clock, TrendingDown, TrendingUp, Timer, BarChart3 } from 'lucide-react';
import { downtimeAnalyticsApi } from '@/api/maintenance/downtimeAnalytics';
import { PageHeader } from '@/components/layout/PageHeader';
import { Chip } from '@/components/ui/Chip';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { Select } from '@/components/ui/Select';
import { Panel } from '@/components/ui/Panel';
import { DowntimeParetoChart, buildParetoData } from '@/components/charts/DowntimeParetoChart';
import type { TopMachineDowntime, MachineDowntimeSummary } from '@/types/maintenance';

const DAYS_OPTIONS = [
  { value: '7', label: 'Last 7 days' },
  { value: '14', label: 'Last 14 days' },
  { value: '30', label: 'Last 30 days' },
  { value: '90', label: 'Last 90 days' },
];

function DowntimeSummaryPlaceholder() {
  return (
    <>
      <div className="h-24 animate-pulse rounded-md bg-elevated" />
      <div className="h-24 animate-pulse rounded-md bg-elevated" />
      <div className="h-24 animate-pulse rounded-md bg-elevated" />
      <div className="h-24 animate-pulse rounded-md bg-elevated" />
    </>
  );
}

function formatMinutes(mins: number): string {
  if (mins >= 60 * 24) {
    const d = Math.floor(mins / (60 * 24));
    const h = Math.floor((mins % (60 * 24)) / 60);
    return `${d}d ${h}h`;
  }
  if (mins >= 60) {
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h}h ${m}m`;
  }
  return `${mins}m`;
}

function StatCard({ label, value, icon: Icon, trend }: { label: string; value: string; icon: typeof Activity; trend?: 'up' | 'down' | 'neutral' }) {
  return (
    <Panel className="p-4">
      <div className="flex items-start justify-between">
        <div>
          <p className="text-2xs uppercase tracking-wider text-muted">{label}</p>
          <p className="mt-1 text-xl font-semibold tabular-nums">{value}</p>
        </div>
        <div className="rounded-md bg-elevated p-2">
          <Icon size={16} className="text-primary" />
        </div>
      </div>
      {trend && (
        <div className="mt-2 flex items-center gap-1 text-2xs">
          {trend === 'up' ? (
            <>
              <TrendingUp size={12} className="text-danger" />
              <span className="text-danger">Higher than avg</span>
            </>
          ) : trend === 'down' ? (
            <>
              <TrendingDown size={12} className="text-success" />
              <span className="text-success">Better than avg</span>
            </>
          ) : null}
        </div>
      )}
    </Panel>
  );
}

export default function DowntimeAnalyticsPage() {
  const [days, setDays] = useState(30);

  const { data: summary, isLoading: summaryLoading } = useQuery({
    queryKey: ['downtime-analytics', 'summary', days],
    queryFn: () => downtimeAnalyticsApi.summary({ days }),
  });

  const { data: trend, isLoading: trendLoading } = useQuery({
    queryKey: ['downtime-analytics', 'daily-trend', days],
    queryFn: () => downtimeAnalyticsApi.dailyTrend({ days }),
  });

  const { data: topMachines, isLoading: topLoading } = useQuery({
    queryKey: ['downtime-analytics', 'top-machines', days],
    queryFn: () => downtimeAnalyticsApi.topMachines({ days, limit: 10 }),
  });

  const { data: allMachines, isLoading: allLoading } = useQuery({
    queryKey: ['downtime-analytics', 'all-machines', days],
    queryFn: () => downtimeAnalyticsApi.allMachines({ days }),
  });

  const maxTrendMinutes = trend && trend.length > 0
    ? Math.max(...trend.map((d) => d.total_minutes))
    : 0;

  const paretoData = useMemo(
    () => buildParetoData(summary?.category_breakdown ?? []),
    [summary],
  );

  const topColumns: Column<TopMachineDowntime>[] = [
    { key: 'machine_code', header: 'Machine', cell: (r) => <span className="font-mono">{r.machine_code}</span> },
    { key: 'name', header: 'Name', cell: (r) => r.name },
    {
      key: 'downtime_minutes',
      header: 'Downtime',
      align: 'right',
      cell: (r) => (
        <div className="flex items-center justify-end gap-2">
          <span className="text-danger-fg">{formatMinutes(r.downtime_minutes)}</span>
        </div>
      ),
    },
    {
      key: 'breakdown_count',
      header: 'Breakdowns',
      align: 'right',
      cell: (r) => (
        <Chip variant={r.breakdown_count >= 3 ? 'danger' : r.breakdown_count >= 1 ? 'warning' : 'success'}>
          {r.breakdown_count}
        </Chip>
      ),
    },
  ];

  const machineColumns: Column<MachineDowntimeSummary>[] = [
    { key: 'code', header: 'Machine', cell: (r) => <span className="font-mono">{r.machine.code}</span> },
    { key: 'name', header: 'Name', cell: (r) => r.machine.name },
    {
      key: 'availability',
      header: 'Availability',
      align: 'right',
      cell: (r) => {
        const pct = r.summary.availability_pct;
        return (
          <Chip variant={pct >= 95 ? 'success' : pct >= 85 ? 'warning' : 'danger'}>
            {pct.toFixed(1)}%
          </Chip>
        );
      },
    },
    {
      key: 'mtbf',
      header: 'MTBF',
      align: 'right',
      cell: (r) => (
        <span className="tabular-nums">{r.summary.mtbf_hours ? `${r.summary.mtbf_hours.toFixed(1)}h` : '—'}</span>
      ),
    },
    {
      key: 'mttr',
      header: 'MTTR',
      align: 'right',
      cell: (r) => (
        <span className="tabular-nums">{r.summary.mttr_minutes ? formatMinutes(r.summary.mttr_minutes) : '—'}</span>
      ),
    },
    {
      key: 'downtime',
      header: 'Downtime',
      align: 'right',
      cell: (r) => (
        <span className="text-danger-fg">{formatMinutes(r.summary.total_downtime_minutes)}</span>
      ),
    },
    {
      key: 'breakdowns',
      header: 'Breakdowns',
      align: 'right',
      cell: (r) => (
        <Chip variant={r.summary.breakdown_count >= 3 ? 'danger' : r.summary.breakdown_count >= 1 ? 'warning' : 'success'}>
          {r.summary.breakdown_count}
        </Chip>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Downtime analytics"
        subtitle="MTBF, MTTR, availability, and breakdown trends"
        actions={
          <div className="w-40">
            <Select value={String(days)} onChange={(e) => setDays(Number(e.target.value))}>
              {DAYS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </Select>
          </div>
        }
      />

      {/* Summary stats */}
      <div className="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
        {summaryLoading ? (
          <DowntimeSummaryPlaceholder />
        ) : summary ? (
          <>
            <StatCard
              label="Total Downtime"
              value={formatMinutes(summary.total_downtime_minutes)}
              icon={Clock}
              trend={summary.total_downtime_minutes > 480 ? 'up' : 'down'}
            />
            <StatCard
              label="MTBF"
              value={summary.mtbf_hours ? `${summary.mtbf_hours.toFixed(1)}h` : 'N/A'}
              icon={Timer}
              trend={summary.mtbf_hours && summary.mtbf_hours > 48 ? 'down' : 'up'}
            />
            <StatCard
              label="MTTR"
              value={summary.mttr_minutes ? formatMinutes(summary.mttr_minutes) : 'N/A'}
              icon={Activity}
              trend={summary.mttr_minutes && summary.mttr_minutes < 60 ? 'down' : 'up'}
            />
            <StatCard
              label="Availability"
              value={`${summary.availability_pct.toFixed(1)}%`}
              icon={BarChart3}
              trend={summary.availability_pct >= 95 ? 'down' : 'up'}
            />
          </>
        ) : null}
      </div>

      {/* Daily trend chart */}
      <div className="px-5 py-4">
        <Panel className="p-4">
          <h3 className="text-sm font-medium">Daily downtime trend</h3>
          {trendLoading ? (
            <div className="mt-4 h-40 animate-pulse rounded bg-elevated" />
          ) : trend && trend.length > 0 ? (
            <div className="mt-4">
              <div className="flex h-40 items-end gap-1">
                {trend.map((d) => {
                  const h = maxTrendMinutes > 0 ? (d.total_minutes / maxTrendMinutes) * 100 : 0;
                  return (
                    <div key={d.date} className="group relative flex flex-1 flex-col items-center">
                      <div className="w-full rounded-t bg-primary/60 hover:bg-primary transition-colors" style={{ height: `${Math.max(h, 2)}%` }} />
                      <div className="mt-1 text-2xs text-muted hidden sm:block">{d.date.slice(5)}</div>
                      <div className="absolute -top-8 left-1/2 hidden -translate-x-1/2 rounded bg-canvas px-2 py-1 text-2xs shadow border border-default group-hover:block whitespace-nowrap">
                        {d.total_minutes}m total / {d.breakdown_minutes}m breakdown
                      </div>
                    </div>
                  );
                })}
              </div>
              <div className="mt-2 flex items-center gap-4 text-2xs text-muted">
                <span className="inline-block h-2 w-2 rounded bg-primary/60" /> Total downtime
                <span className="inline-block h-2 w-2 rounded bg-danger" /> Breakdown
              </div>
            </div>
          ) : (
            <EmptyState icon="activity" title="No downtime data" description="No downtime recorded in the selected period." className="mt-4" />
          )}
        </Panel>
      </div>

      {/* Top offending machines */}
      <div className="px-5 py-4">
        <Panel className="p-4">
          <h3 className="text-sm font-medium">Top machines by downtime</h3>
          {topLoading ? (
            <SkeletonTable columns={4} rows={5} />
          ) : topMachines && topMachines.length > 0 ? (
            <div className="mt-4">
              <DataTable columns={topColumns} data={topMachines} />
            </div>
          ) : (
            <EmptyState icon="wrench" title="No data" description="All machines are operating normally." className="mt-4" />
          )}
        </Panel>
      </div>

      {/* All machines summary */}
      <div className="px-5 py-4">
        <Panel className="p-4">
          <h3 className="text-sm font-medium">Machine availability summary</h3>
          {allLoading ? (
            <SkeletonTable columns={7} rows={5} />
          ) : allMachines && allMachines.length > 0 ? (
            <div className="mt-4">
              <DataTable columns={machineColumns} data={allMachines} />
            </div>
          ) : (
            <EmptyState icon="wrench" title="No machines found" className="mt-4" />
          )}
        </Panel>
      </div>

      {/* Pareto chart — downtime by category */}
      {(summaryLoading || (summary && summary.category_breakdown.length > 0)) && (
        <div className="px-5 py-4">
          <Panel className="p-4">
            <h3 className="text-sm font-medium mb-4">Downtime Pareto — by category</h3>
            {summaryLoading ? (
              <div className="h-64 animate-pulse rounded bg-elevated" />
            ) : (
              <DowntimeParetoChart data={paretoData} height={264} />
            )}
          </Panel>
        </div>
      )}
    </div>
  );
}
