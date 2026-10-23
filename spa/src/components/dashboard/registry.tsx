import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { SparkLine } from '@/components/charts/SparkLine';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Chip } from '@/components/ui/Chip';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { WidgetErrorBoundary } from '@/components/ui/WidgetErrorBoundary';
import { formatDate, formatTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { isStaleTimestamp } from '@/api/dashboard-layout';
import { WidgetBreakdown } from './WidgetBreakdown';
import { WidgetTable } from './WidgetTable';
import { WidgetExportButton } from './WidgetExportButton';
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
  // The approvals tile carries per-row links and delegation badges, which the
  // generic WidgetTable cannot render — so it gets its own renderer. Read-only:
  // rows navigate to the entity page or the board, act lives there.
  if (widget.key === 'approvals.pending') {
  return <ApprovalsPendingTable data={data as WidgetTableData} />;
  }
  if (widget.key === 'executive.risks_opportunities') {
  return <ExecutiveRecommendationsTable data={data as WidgetTableData} />;
  }
  return <WidgetTable {...(data as WidgetTableData)} />;
  case 'gauge':
  return <RichGauge {...(data as WidgetGaugeData)} />;
  default:
  return null;
  }
}

/**
 * The `approvals.pending` tile: the caller's top-8 queue as Document|Type|Waiting.
 *
 * Rows come from `ApprovalsWidgetAnalytics` via the board's row scope, so a
 * Restricted row is a delegation outside the caller's module scope — badge, no
 * link, no way in. Overdue rows count down past the approval SLA.
 */
function ApprovalsPendingTable({ data }: { data: WidgetTableData }) {
  const navigate = useNavigate();
  const rows = useMemo(
    () =>
      data.rows.map((raw) => ({
        document: String(raw.document ?? '—'),
        type: String(raw.type ?? ''),
        typeLabel: raw.type_label != null && raw.type_label !== '' ? String(raw.type_label) : String(raw.type ?? ''),
        waitingH: typeof raw.waiting_h === 'number' ? raw.waiting_h : Number(raw.waiting_h ?? 0),
        overdueH: typeof raw.overdue_hours === 'number' ? raw.overdue_hours : Number(raw.overdue_hours ?? 0),
        link: raw.link != null && raw.link !== '' ? String(raw.link) : null,
        badge: raw.delegation_badge != null && raw.delegation_badge !== '' ? String(raw.delegation_badge) : null,
      })),
    [data.rows],
  );
  const slaHours = typeof data.sla_hours === 'number' ? data.sla_hours : null;

  const types = useMemo(() => [...new Set(rows.map((r) => r.typeLabel))], [rows]);
  const [filter, setFilter] = useState<string>('all');
  const visible = filter === 'all' ? rows : rows.filter((r) => r.typeLabel === filter);

  if (rows.length === 0) {
    return <p className="text-xs text-muted">Nothing outstanding.</p>;
  }

  return (
    <div className="space-y-2">
      {types.length > 1 && (
        <SegmentedControl
          size="sm"
          label="Approval type"
          value={filter}
          onChange={setFilter}
          options={[
            { value: 'all', label: 'All', count: rows.length },
            ...types.map((t) => ({
              value: t,
              label: t,
              count: rows.filter((r) => r.typeLabel === t).length,
            })),
          ]}
        />
      )}
      <table className="w-full text-xs">
        <thead>
          <tr className="border-b border-subtle text-left">
            <th scope="col" className="pb-1 font-medium text-muted">Document</th>
            <th scope="col" className="pb-1 font-medium text-muted">Type</th>
            <th scope="col" className="pb-1 font-medium text-muted text-right">Waiting</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-subtle">
          {visible.map((row, index) => (
            <tr
              key={`${row.document}-${index}`}
              onClick={row.link ? () => navigate(row.link as string) : undefined}
              className={row.link ? 'cursor-pointer hover:bg-subtle transition-colors duration-fast' : undefined}
            >
              <td className="py-1.5 text-primary">
                {row.link ? (
                  <Link to={row.link} className="text-link hover:underline font-mono tabular-nums">
                    {row.document}
                  </Link>
                ) : (
                  <span className="font-mono tabular-nums">{row.document}</span>
                )}
                {row.badge && (
                  <Chip variant="info" className="ml-1">{row.badge}</Chip>
                )}
              </td>
              <td className="py-1.5 text-primary">{row.typeLabel}</td>
              <td className="py-1.5 text-primary text-right font-mono tabular-nums">
                {row.waitingH}h
                {row.overdueH > 0 ? (
                  <span className="text-danger-fg"> · overdue {row.overdueH}h</span>
                ) : slaHours !== null ? (
                  <span className="text-subtle"> · {slaHours - row.waitingH}h left</span>
                ) : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {data.total_count > rows.length && (
        <p className="text-2xs text-subtle">
          Showing {rows.length} of {data.total_count.toLocaleString()}
        </p>
      )}
    </div>
  );
}

function ExecutiveRecommendationsTable({ data }: { data: WidgetTableData }) {
  if (data.rows.length === 0) {
    return <p className="text-xs text-muted">No current risks or opportunities flagged.</p>;
  }

  return (
    <div className="space-y-2">
      <table className="w-full text-xs">
        <thead>
          <tr className="border-b border-subtle text-left">
            <th scope="col" className="pb-1 font-medium text-muted">Priority</th>
            <th scope="col" className="pb-1 font-medium text-muted">Signal</th>
            <th scope="col" className="pb-1 font-medium text-muted">Next step</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-subtle">
          {data.rows.map((row, index) => (
            <tr key={`${String(row.priority ?? '')}-${index}`}>
              <td className="py-1.5 text-primary">{row.priority}</td>
              <td className="py-1.5 text-primary">{row.signal}</td>
              <td className="py-1.5 text-primary">
                {row.link ? (
                  <Link to={String(row.link)} className="text-link hover:underline">
                    {row.recommendation}
                  </Link>
                ) : row.recommendation}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {data.total_count > data.rows.length && (
        <p className="text-2xs text-subtle">
          Showing {data.rows.length} of {data.total_count.toLocaleString()}
        </p>
      )}
    </div>
  );
}

export function LiveDashboardWidget({
  widget,
  summary,
  loading,
  onRetry,
}: {
  widget: DashboardLayoutItem;
  summary?: DashboardWidgetSummary;
  loading: boolean;
  /** Re-fires the widget-data query from the tile fallback. Omitted = reload fallback. */
  onRetry?: () => void;
}) {
  const href = widget.link_path;
  const richContent = !loading ? renderRichWidget(widget) : null;
  const stale = isStaleTimestamp(summary?.updated_at);

  return (
    <Panel
      title={widget.name}
      actions={
        <div className="flex items-center gap-1.5">
          {href && (
            <Link to={href} className="text-xs text-link hover:underline">
              Open →
            </Link>
          )}
          {/* Every tile can take its own numbers with it (F-017). The button
              hides itself when the payload is unavailable — see the component. */}
          <WidgetExportButton
            name={widget.name}
            renderKind={widget.render_kind}
            data={widget.data}
            summary={summary}
            updatedAt={summary?.updated_at}
          />
        </div>
      }
    >
      {loading ? (
        <div className="space-y-2">
          <SkeletonBlock className="h-8 w-28 rounded" />
          <SkeletonBlock className="h-4 w-44 rounded" />
        </div>
      ) : (
        <WidgetErrorBoundary onRetry={onRetry} resetKeys={[widget.data, summary ?? null]}>
          {richContent ?? (!summary || !summary.available ? (
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
            </div>
          ))}
          {/* Freshness footer on every tile that carries a scalar summary —
              rich or scalar. Stale is words (Chip), never colour alone. */}
          {summary?.updated_at ? (
            <p className="text-2xs text-subtle mt-1">
              Updated {formatTime(summary.updated_at)}
              {stale && (
                <Chip variant="warning" className="ml-1">
                  Stale
                </Chip>
              )}
            </p>
          ) : null}
        </WidgetErrorBoundary>
      )}
    </Panel>
  );
}
