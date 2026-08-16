import { Link } from 'react-router-dom';
import { SparkLine } from '@/components/charts/SparkLine';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { WidgetBreakdown } from './WidgetBreakdown';
import { WidgetTable } from './WidgetTable';
import type {
 DashboardLayoutItem,
 DashboardWidgetSummary,
 WidgetBreakdownData,
 WidgetData,
 WidgetGaugeData,
 WidgetTableData,
 WidgetTrendData,
} from '@/api/dashboard-layout';

/** Mirrors the backend KpiStatus enum. Words, so status never rides on colour. */
const KPI_STATUS_LABELS: Record<string, string> = {
  on_target: 'On target',
  warning: 'Warning',
  off_target: 'Off target',
};


function formatValue(summary: DashboardWidgetSummary): string {
  if (summary.value === null) return '—';
  if (summary.kind === 'currency') return formatPeso(summary.value);
  if (summary.kind === 'percent') return `${Number(summary.value).toFixed(1)}%`;
  if (summary.kind === 'hours') return `${Number(summary.value).toFixed(2)} h`;
  if (summary.kind === 'date') return formatDate(summary.value);
  if (summary.kind === 'decimal')
    return Number(summary.value).toLocaleString(undefined, { maximumFractionDigits: 2 });
 return Number(summary.value).toLocaleString();
}

function formatRichValue(value: number, kind: DashboardWidgetSummary['kind']): string {
 if (kind === 'currency') return formatPeso(value);
 if (kind === 'percent') return `${value.toFixed(1)}%`;
 if (kind === 'hours') return `${value.toFixed(2)} h`;
 if (kind === 'decimal') return value.toLocaleString(undefined, { maximumFractionDigits: 2 });
 return value.toLocaleString();
}

function RichGauge({ value, target, min, max, kind }: WidgetGaugeData) {
 const range = max > min ? max - min : 1;
 const progress = ((value - min) / range) * 100;

 return (
 <div className="space-y-2">
 <div className="flex items-baseline justify-between gap-2">
 <span className="text-2xl font-mono tabular-nums font-medium text-primary">
 {formatRichValue(value, kind)}
 </span>
 {target !== null && (
 <span className="text-xs text-muted">Target {formatRichValue(target, kind)}</span>
 )}
 </div>
 <ProgressBar
 value={progress}
 height="2"
 ariaLabel={`${formatRichValue(value, kind)} of target range`}
 />
 </div>
 );
}

function RichTrend({ points, delta, kind, target, status }: WidgetTrendData) {
 if (points.length === 0) return <p className="text-xs text-muted">No trend data.</p>;

 const latest = points[points.length - 1]?.value ?? 0;
 return (
 <div className="space-y-2">
 <div className="flex items-baseline justify-between gap-2">
 <span className="text-2xl font-mono tabular-nums font-medium text-primary">
 {formatRichValue(latest, kind)}
 </span>
 {delta !== null && (
 <span className={delta >= 0 ? 'text-xs text-success-fg' : 'text-xs text-danger-fg'}>
 {delta >= 0 ? '+' : ''}{delta.toFixed(1)}%
 </span>
 )}
 </div>
 <SparkLine data={points.map((point) => point.value)} height={32} width={160} />
 {/* A scorecard KPI is only readable against its target, so both are shown
     when the provider supplies them. Status carries its own words, never
     colour alone — DESIGN-SYSTEM.md / WCAG 1.4.1. */}
 {target !== undefined && target !== null ? (
 <p className="text-2xs text-subtle">
 Target {formatRichValue(target, kind)}
 {status ? ` · ${KPI_STATUS_LABELS[status] ?? status}` : ''} · {points.length} periods
 </p>
 ) : (
 <p className="text-2xs text-subtle">Latest of {points.length} periods</p>
 )}
 </div>
 );
}

function renderRichWidget(widget: DashboardLayoutItem): React.ReactNode | null {
 const data = widget.data as WidgetData | null;
 if (!data) return null;

 switch (widget.render_kind) {
 case 'breakdown':
 return <WidgetBreakdown {...(data as WidgetBreakdownData)} />;
 case 'trend':
 return <RichTrend {...(data as WidgetTrendData)} />;
 case 'table':
 return <WidgetTable {...(data as WidgetTableData)} />;
 case 'gauge':
 return <RichGauge {...(data as WidgetGaugeData)} />;
 default:
 return null;
 }
}

export function LiveDashboardWidget({
  widget,
  summary,
  loading,
}: {
  widget: DashboardLayoutItem;
  summary?: DashboardWidgetSummary;
  loading: boolean;
}) {
  const href = widget.link_path;
  const richContent = !loading ? renderRichWidget(widget) : null;

  return (
    <Panel
      title={widget.name}
      actions={
        href ? (
          <Link to={href} className="text-xs text-link hover:underline">
            Open →
          </Link>
        ) : undefined
      }
    >
      {loading ? (
        <div className="space-y-2">
          <SkeletonBlock className="h-8 w-28 rounded" />
          <SkeletonBlock className="h-4 w-44 rounded" />
        </div>
      ) : richContent ? (
        richContent
      ) : !summary || !summary.available ? (
        <EmptyState
          size="compact"
          icon="alert-circle"
          title="Live data unavailable"
          description={summary?.helper ?? 'This widget has no live response.'}
        />
      ) : (
        <div className="space-y-1.5">
          <div className="text-2xl font-mono tabular-nums font-medium text-primary">
            {formatValue(summary)}
          </div>
          {summary.helper && <p className="text-xs text-muted">{summary.helper}</p>}
          <p className="text-2xs text-subtle">
            Updated {new Date(summary.updated_at).toLocaleTimeString()}
          </p>
        </div>
      )}
    </Panel>
  );
}
