import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { TrendingUp, TrendingDown, Minus, RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import { LineChart, Line, ResponsiveContainer } from 'recharts';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import { kpiApi } from '@/api/kpi';
import type { KpiScorecardItem, KpiTrendPoint } from '@/types/dashboard/kpi';

const MODULE_LINKS: Record<string, string> = {
  production: '/production/work-orders',
  quality: '/quality/inspections',
  supply_chain: '/supply-chain/deliveries',
  purchasing: '/purchasing/purchase-orders',
  attendance: '/hr/attendance',
  accounting: '/accounting/journal-entries',
  inventory: '/inventory/items',
};

const UNIT_SUFFIX: Record<string, string> = {
  percentage: '%',
  days: ' days',
  currency: '',
  count: '',
  ratio: 'x',
};

const STATUS_DOT: Record<string, string> = {
  on_target: 'bg-emerald-500',
  warning: 'bg-amber-500',
  off_target: 'bg-red-500',
};

const TREND_COLOR: Record<string, string> = {
  on_target: 'var(--color-success)',
  warning: 'var(--color-warning)',
  off_target: 'var(--color-danger)',
};

const MONTHS = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

function formatKpiValue(value: string, unit: string): string {
  const num = parseFloat(value);
  if (isNaN(num)) return value;
  if (unit === 'currency') {
    return `₱${num.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
  if (unit === 'percentage' || unit === 'ratio') {
    return num.toFixed(2);
  }
  if (unit === 'days') {
    return num.toFixed(1);
  }
  return num.toLocaleString();
}

export default function ScorecardPage() {
  const { can } = usePermission();
  const queryClient = useQueryClient();

  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);

  const scorecardQ = useQuery({
    queryKey: ['kpi', 'scorecard', year, month],
    queryFn: () => kpiApi.scorecard(year, month).then((r) => r.data.data),
    placeholderData: (prev) => prev,
  });

  const computeMut = useMutation({
    mutationFn: () => kpiApi.compute(year, month),
    onSuccess: (res) => {
      toast.success(res.data.message);
      queryClient.invalidateQueries({ queryKey: ['kpi'] });
    },
    onError: () => {
      toast.error('Failed to compute KPIs');
    },
  });

  // Generate year options (last 3 years + current)
  const yearOptions = useMemo(() => {
    const currentYear = new Date().getFullYear();
    return Array.from({ length: 4 }, (_, i) => currentYear - 3 + i);
  }, []);

  return (
    <div>
      <PageHeader
        title="KPI Scorecard"
        subtitle="Monthly performance indicators across all modules."
        refreshingQueryKey={['kpi', 'scorecard', year, month]}
        actions={
          <div className="flex items-center gap-2">
            <select
              value={month}
              onChange={(e) => setMonth(Number(e.target.value))}
              className="h-8 rounded-md border border-default bg-canvas px-2 text-sm text-primary focus:outline-none focus:ring-1 focus:ring-accent"
              aria-label="Month"
            >
              {MONTHS.map((m, i) => (
                <option key={i} value={i + 1}>{m}</option>
              ))}
            </select>
            <select
              value={year}
              onChange={(e) => setYear(Number(e.target.value))}
              className="h-8 rounded-md border border-default bg-canvas px-2 text-sm text-primary focus:outline-none focus:ring-1 focus:ring-accent"
              aria-label="Year"
            >
              {yearOptions.map((y) => (
                <option key={y} value={y}>{y}</option>
              ))}
            </select>
            {can('dashboard.admin.view') && (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => computeMut.mutate()}
                disabled={computeMut.isPending}
              >
                <RefreshCw size={14} className={computeMut.isPending ? 'animate-spin' : ''} />
                {computeMut.isPending ? 'Computing...' : 'Compute KPIs'}
              </Button>
            )}
          </div>
        }
      />

      <div className="px-5 py-4">
        {scorecardQ.isLoading && !scorecardQ.data && <SkeletonDetail />}

        {scorecardQ.isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load scorecard"
            description="Could not fetch KPI data. Please try again."
            action={
              <Button variant="secondary" onClick={() => scorecardQ.refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {scorecardQ.data && scorecardQ.data.length === 0 && (
          <EmptyState
            icon="bar-chart"
            title="No KPIs defined"
            description="KPI definitions have not been configured yet."
          />
        )}

        {scorecardQ.data && scorecardQ.data.length > 0 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            {scorecardQ.data.map((item) => (
              <KpiCard key={item.definition.code} item={item} year={year} month={month} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

function KpiCard({ item, year, month }: { item: KpiScorecardItem; year: number; month: number }) {
  const { definition: def, snapshot } = item;
  const moduleLink = MODULE_LINKS[def.module];

  // Fetch trend data for the sparkline
  const trendQ = useQuery({
    queryKey: ['kpi', 'trend', def.code],
    queryFn: () => kpiApi.trend(def.code, 6).then((r) => r.data.data),
    staleTime: 5 * 60 * 1000,
  });

  const sparkData = useMemo(() => {
    if (!trendQ.data) return [];
    return trendQ.data.map((p: KpiTrendPoint) => ({ v: parseFloat(p.value) }));
  }, [trendQ.data]);

  const status = snapshot?.status ?? 'on_target';
  const trend = snapshot?.trend ?? 'flat';
  const actualDisplay = snapshot
    ? formatKpiValue(snapshot.actual_value, def.unit) + (UNIT_SUFFIX[def.unit] ?? '')
    : '--';
  const targetDisplay = def.target_value !== null
    ? `Target: ${formatKpiValue(def.target_value, def.unit)}${UNIT_SUFFIX[def.unit] ?? ''}`
    : 'No target set';

  // Determine if trend direction is good or bad based on KPI direction
  const trendIsPositive = trend === 'up'
    ? def.direction === 'higher_is_better'
    : trend === 'down'
      ? def.direction === 'lower_is_better'
      : true; // flat is neutral

  const TrendIcon = trend === 'up' ? TrendingUp : trend === 'down' ? TrendingDown : Minus;
  const trendColor = trend === 'flat'
    ? 'text-muted'
    : trendIsPositive ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400';

  const card = (
    <Panel className="group relative transition-colors duration-fast hover:bg-elevated">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5 mb-2">
            <span
              className={`inline-block h-2 w-2 rounded-full shrink-0 ${STATUS_DOT[status] ?? 'bg-gray-400'}`}
              title={status.replace(/_/g, ' ')}
              aria-label={`Status: ${status.replace(/_/g, ' ')}`}
            />
            <span className="text-2xs uppercase tracking-wider text-text-subtle font-display font-medium truncate">
              {def.name}
            </span>
          </div>

          <div className="flex items-baseline gap-2">
            <span className="text-2xl font-medium font-mono tabular-nums text-primary leading-tight">
              {actualDisplay}
            </span>
            <TrendIcon size={16} className={trendColor} aria-label={`Trend: ${trend}`} />
          </div>

          <div className="text-xs text-muted mt-1">{targetDisplay}</div>
        </div>

        {/* Sparkline */}
        {sparkData.length >= 2 && (
          <div className="shrink-0 pt-2">
            <ResponsiveContainer width={72} height={36}>
              <LineChart data={sparkData}>
                <Line
                  type="monotone"
                  dataKey="v"
                  stroke={TREND_COLOR[status] ?? 'var(--color-accent)'}
                  strokeWidth={1.5}
                  dot={false}
                />
              </LineChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>

      {snapshot?.previous_value !== null && snapshot?.previous_value !== undefined && (
        <div className="text-2xs text-muted mt-2">
          Prev: {formatKpiValue(snapshot.previous_value, def.unit)}{UNIT_SUFFIX[def.unit] ?? ''}
        </div>
      )}
    </Panel>
  );

  if (moduleLink) {
    return (
      <Link to={moduleLink} className="block rounded-md focus:outline-none focus:ring-2 focus:ring-accent">
        {card}
      </Link>
    );
  }

  return card;
}
