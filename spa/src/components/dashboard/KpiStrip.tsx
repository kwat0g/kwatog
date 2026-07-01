import { useQuery } from '@tanstack/react-query';
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { Link } from 'react-router-dom';
import { kpiApi } from '@/api/kpi';
import { cn } from '@/lib/cn';
import type { KpiScorecardItem } from '@/types/dashboard/kpi';

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

const TREND_COLORS: Record<string, Record<string, string>> = {
  on_target: { up: 'text-emerald-500', down: 'text-emerald-500', flat: 'text-muted' },
  warning: { up: 'text-amber-500', down: 'text-amber-500', flat: 'text-muted' },
  off_target: { up: 'text-red-500', down: 'text-red-500', flat: 'text-muted' },
};

const TrendIcon = ({ trend, status }: { trend: string; status: string }) => {
  const color = TREND_COLORS[status]?.[trend] ?? 'text-muted';
  if (trend === 'up') return <TrendingUp size={12} className={color} />;
  if (trend === 'down') return <TrendingDown size={12} className={color} />;
  return <Minus size={12} className="text-muted" />;
};

interface KpiStripProps {
  codes: string[];
  className?: string;
}

export function KpiStrip({ codes, className }: KpiStripProps) {
  const now = new Date();
  const prevMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  const year = prevMonth.getFullYear();
  const month = prevMonth.getMonth() + 1;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['kpi', 'scorecard', year, month],
    queryFn: () => kpiApi.scorecard(year, month).then((r) => r.data.data),
    staleTime: 5 * 60_000,
    retry: 1,
  });

  if (isError) return null;

  if (isLoading || !data) {
    return (
      <div className={cn('grid gap-3', className)} style={{ gridTemplateColumns: `repeat(${codes.length}, minmax(0, 1fr))` }}>
        {codes.map((c) => (
          <div key={c} className="h-[72px] bg-surface border border-default rounded-md animate-pulse" />
        ))}
      </div>
    );
  }

  const filtered = codes
    .map((code) => data.find((item: KpiScorecardItem) => item.definition.code === code))
    .filter(Boolean) as KpiScorecardItem[];

  if (filtered.length === 0) return null;

  return (
    <div className={cn('grid gap-3', className)} style={{ gridTemplateColumns: `repeat(${filtered.length}, minmax(0, 1fr))` }}>
      {filtered.map((item) => {
        const snap = item.snapshot;
        const val = snap ? `${parseFloat(snap.actual_value).toLocaleString()}${UNIT_SUFFIX[item.definition.unit] ?? ''}` : '—';
        const target = item.definition.target_value ? `Target: ${parseFloat(item.definition.target_value).toLocaleString()}${UNIT_SUFFIX[item.definition.unit] ?? ''}` : undefined;

        return (
          <Link
            key={item.definition.code}
            to="/dashboard/scorecard"
            className="p-3 bg-surface border border-default rounded-md hover:bg-elevated transition-colors duration-fast flex items-start justify-between gap-2"
          >
            <div className="min-w-0">
              <div className="text-2xs uppercase tracking-wider text-text-subtle font-display font-medium mb-1 truncate">
                {item.definition.name}
              </div>
              <div className="text-lg font-medium font-mono tabular-nums text-primary leading-tight">
                {val}
              </div>
              {target && <div className="text-2xs text-muted mt-0.5">{target}</div>}
            </div>
            {snap && (
              <div className="flex items-center gap-1 mt-0.5 shrink-0">
                <span className={cn('w-1.5 h-1.5 rounded-full', STATUS_DOT[snap.status] ?? 'bg-gray-400')} />
                <TrendIcon trend={snap.trend} status={snap.status} />
              </div>
            )}
          </Link>
        );
      })}
    </div>
  );
}
