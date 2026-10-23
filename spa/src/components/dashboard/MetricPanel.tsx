/**
 * MetricPanel — the SPA's consumer for the Phase 2 analytics layer.
 *
 * One component for every metric: it takes a metric CODE, not a payload, and
 * reads the envelope the API already shaped (`data.definition` beside
 * `data.value`, `meta` carrying period/scope/cached/caveats). That is the point
 * of the layer — a page mounts a code and gets the five states, the caveats and
 * the drill link without knowing a permission, a query or a payload shape.
 *
 * Body selection is driven by `definition.render_kind` (the same discriminator
 * the widget registry uses), so a new metric draws without new SPA code:
 *
 *   breakdown → total + one bar per segment (reuses `WidgetBreakdown`)
 *   trend     → latest value + a sparkline over the buckets
 *   scalar    → the single figure
 *
 * Any remaining top-level scalars in the payload are listed as a secondary
 * stat row — `oldest_open_days`, `awaiting_approval`, `completed` — so the
 * numbers that did not get a bar are still visible. Plain objects of numbers
 * are flattened one level (`handoff.awaiting_inspection`), which is how the
 * chain-handoff counts reach the tile instead of being dropped.
 *
 * Read-only by construction: no input, no recompute, no override. Acting on a
 * number happens on the page that owns it; the panel links there.
 */
import { Link } from 'react-router-dom';
import { SparkLine } from '@/components/charts/SparkLine';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { LinkButton } from '@/components/ui/LinkButton';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { formatInt } from '@/lib/formatNumber';
import { formatMetricValue } from '@/lib/metricValue';
import { useMetric } from '@/hooks/useMetric';
import { WidgetBreakdown } from './WidgetBreakdown';
import { CsvDownloadButton } from './WidgetExportButton';
import { metricExportTable } from '@/lib/widgetExport';
import type { WidgetValueKind } from '@/api/dashboard-layout';
import type {
  MetricEnvelopeMeta,
  MetricPeriodPreset,
  MetricRenderableValue,
  MetricSegmentTone,
  MetricUnit,
} from '@/types/analytics';

interface Props {
  /** Registry code, e.g. `returns.pipeline`. Must contain no slash. */
  code: string;
  /** Override the dictionary label. Defaults to `definition.label`. */
  title?: string;
  period?: MetricPeriodPreset;
  from?: string;
  to?: string;
  machine_id?: number;
  enabled?: boolean;
  /** Read-only link-out to the surface that owns the number. */
  detailsHref?: string;
  className?: string;
}

/** `MetricUnit` → the widget value vocabulary `WidgetBreakdown` formats with. */
const WIDGET_KIND: Record<MetricUnit, WidgetValueKind> = {
  percent: 'percent',
  currency: 'currency',
  count: 'count',
  hours: 'hours',
  days: 'number',
  ratio: 'decimal',
};

/** `oldest_open_days` → `Oldest open days`. No per-metric label map needed. */
function humanize(key: string): string {
  const words = key.replace(/_/g, ' ').trim();
  return words.charAt(0).toUpperCase() + words.slice(1);
}

function asNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function asSegments(
  value: unknown,
): Array<{ label: string; value: number; tone: MetricSegmentTone }> | null {
  if (!Array.isArray(value)) return null;

  const out: Array<{ label: string; value: number; tone: MetricSegmentTone }> = [];

  for (const entry of value) {
    if (!entry || typeof entry !== 'object') return null;
    const row = entry as Record<string, unknown>;
    const amount = asNumber(row.value);

    if (typeof row.label !== 'string' || amount === null) return null;

    out.push({
      label: row.label,
      value: amount,
      tone: (row.tone as MetricSegmentTone | undefined) ?? 'neutral',
    });
  }

  return out;
}

function asPoints(value: unknown): Array<{ label: string; value: number }> | null {
  if (!Array.isArray(value)) return null;

  const out: Array<{ label: string; value: number }> = [];

  for (const entry of value) {
    if (!entry || typeof entry !== 'object') return null;
    const row = entry as Record<string, unknown>;
    const amount = asNumber(row.value);

    if (typeof row.label !== 'string' || amount === null) return null;

    out.push({ label: row.label, value: amount });
  }

  return out;
}

/**
 * The headline figure the render kind puts first. Kept separate from the stat
 * row so the same key never appears twice.
 */
function headline(
  renderKind: string,
  value: MetricRenderableValue,
): { label: string; amount: number } | null {
  if (renderKind === 'trend') {
    const points = asPoints(value.points);

    if (points && points.length > 0) {
      return { label: 'Latest period', amount: points[points.length - 1].value };
    }

    return null;
  }

  if (renderKind === 'breakdown') {
    const total = asNumber(value.total);
    return total === null ? null : { label: 'Total', amount: total };
  }

  const scalar = asNumber(value.value) ?? asNumber(value.total);
  return scalar === null ? null : { label: 'Value', amount: scalar };
}

/**
 * Secondary scalars: everything numeric and non-null that the headline did not
 * already take. `*_id` columns are skipped — they are join keys, not figures —
 * and nested objects of numbers are flattened one level so chain-handoff counts
 * survive. Arrays are dropped here; the render kind owns the list shapes.
 */
function secondaryStats(
  value: MetricRenderableValue,
  takenKeys: Set<string>,
): Array<{ label: string; amount: number }> {
  const out: Array<{ label: string; amount: number }> = [];

  for (const [key, raw] of Object.entries(value)) {
    if (takenKeys.has(key) || key.endsWith('_id') || key === 'unit') continue;

    const amount = asNumber(raw);

    if (amount !== null) {
      out.push({ label: humanize(key), amount });
      continue;
    }

    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      for (const [nestedKey, nestedRaw] of Object.entries(raw as Record<string, unknown>)) {
        const nestedAmount = asNumber(nestedRaw);

        if (nestedAmount !== null) {
          out.push({ label: `${humanize(key)} · ${humanize(nestedKey)}`, amount: nestedAmount });
        }
      }
    }
  }

  return out.slice(0, 6);
}

/** True when the payload carries no observation for the window. */
function isEmpty(renderKind: string, value: MetricRenderableValue): boolean {
  if (renderKind === 'trend') {
    const points = asPoints(value.points);
    return points !== null && points.length === 0;
  }

  if (renderKind === 'breakdown') {
    const segments = asSegments(value.segments);
    return (asNumber(value.total) ?? 0) === 0 && (segments?.length ?? 0) === 0;
  }

  return Object.keys(value).length === 0;
}

function MetaLine({ meta, isStale }: { meta: MetricEnvelopeMeta; isStale: boolean }) {
  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <span className="font-mono tabular-nums text-2xs text-subtle">
        {meta.period.from} → {meta.period.to}
      </span>
      {isStale && <Chip variant="warning">stale</Chip>}
      {meta.cached && <Chip variant="info">cached</Chip>}
      {meta.scope !== 'company' && <Chip variant="neutral">{meta.scope}</Chip>}
    </span>
  );
}

function Body({
  renderKind,
  unit,
  value,
}: {
  renderKind: string;
  unit: MetricUnit;
  value: MetricRenderableValue;
}) {
  const hero = headline(renderKind, value);

  if (renderKind === 'breakdown') {
    const segments = asSegments(value.segments);
    const total = asNumber(value.total) ?? 0;

    return (
      <div className="space-y-3">
        {/* `WidgetBreakdown` only draws its own total alongside the bars, so an
            empty breakdown (a total whose segment rows were all omitted) would
            render "Nothing to break down." and swallow the total entirely.
            Draw the figure itself in that case rather than lose it. */}
        {segments && segments.length > 0 ? (
          <WidgetBreakdown total={total} segments={segments} kind={WIDGET_KIND[unit]} />
        ) : (
          <div className="text-2xl font-mono tabular-nums font-medium text-primary">
            {formatMetricValue(total, unit)}
          </div>
        )}
        <SecondaryStats value={value} />
      </div>
    );
  }

  if (renderKind === 'trend') {
    const points = asPoints(value.points) ?? [];

    return (
      <div className="space-y-2">
        {hero && (
          <div className="flex items-baseline justify-between gap-2">
            <span className="text-2xl font-mono tabular-nums font-medium text-primary">
              {formatMetricValue(hero.amount, unit)}
            </span>
            <span className="text-2xs text-subtle">
              {hero.label} of {points.length} periods
            </span>
          </div>
        )}
        <SparkLine data={points.map((point) => point.value)} height={36} width={220} />
        <div className="flex justify-between text-2xs text-subtle">
          <span>{points[0]?.label}</span>
          <span>{points[points.length - 1]?.label}</span>
        </div>
      </div>
    );
  }

  return (
    <div className="text-2xl font-mono tabular-nums font-medium text-primary">
      {hero ? formatMetricValue(hero.amount, unit) : '—'}
    </div>
  );
}

/** The secondary figure list — the numbers that did not get a bar or a line. */
function SecondaryStats({ value }: { value: MetricRenderableValue }) {
  const stats = secondaryStats(value, new Set(['total']));

  if (stats.length === 0) return null;

  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 border-t border-subtle pt-2">
      {stats.map((stat) => (
        <div key={stat.label} className="flex items-baseline justify-between gap-2">
          <dt className="truncate text-2xs text-muted">{stat.label}</dt>
          <dd className="font-mono text-xs tabular-nums text-primary">{formatInt(stat.amount)}</dd>
        </div>
      ))}
    </dl>
  );
}

export function MetricPanel({
  code,
  title,
  period = 'month',
  from,
  to,
  machine_id,
  enabled = true,
  detailsHref,
  className,
}: Props) {
  const { envelope, isLoading, isError, isStale, refetch } = useMetric<MetricRenderableValue>(code, {
    period,
    from,
    to,
    machine_id,
    enabled,
  });

  const definition = envelope?.data.definition;
  const heading = title ?? definition?.label ?? code;

  if (isLoading && !envelope) {
    return (
      <Panel title={heading} className={className}>
        <div className="space-y-2">
          {[1, 2, 3].map((i) => (
            <SkeletonBlock key={i} className="h-6 w-full rounded-md" />
          ))}
        </div>
      </Panel>
    );
  }

  if (isError || !envelope || !definition) {
    return (
      <Panel title={heading} className={className}>
        <EmptyState
          icon="alert-circle"
          title="Failed to load this metric"
          description="The analytics layer refused or could not answer. The metric exists only if your role holds its gate."
          action={<LinkButton onClick={refetch} className="text-sm">Retry</LinkButton>}
        />
      </Panel>
    );
  }

  const value = envelope.data.value;
  const empty = isEmpty(definition.render_kind, value);
  // Same rows the body draws, so the file and the tile cannot disagree. An
  // empty window exports nothing — there is no figure to carry away.
  const exportTable = empty
    ? null
    : metricExportTable({
        name: heading,
        renderKind: definition.render_kind,
        unit: WIDGET_KIND[definition.unit],
        value,
      });

  return (
    <Panel
      title={heading}
      meta={<MetaLine meta={envelope.meta} isStale={isStale} />}
      actions={
        <div className="flex items-center gap-1.5">
          {detailsHref && (
            <Link to={detailsHref} className="text-xs text-link hover:underline">
              Open →
            </Link>
          )}
          {exportTable && (
            <CsvDownloadButton
              name={heading}
              table={exportTable}
              timestamp={envelope.meta.period.to}
            />
          )}
        </div>
      }
      className={className}
    >
      {empty ? (
        <EmptyState
          icon="inbox"
          title="No data in this window"
          description={`No ${definition.label.toLowerCase()} recorded between ${envelope.meta.period.from} and ${envelope.meta.period.to}.`}
        />
      ) : (
        <div className="space-y-3">
          <Body renderKind={definition.render_kind} unit={definition.unit} value={value} />

          {/* The definition travels with the number, so "explain this figure"
              needs no second request — and a caveat is stated on the tile
              rather than in a doc the reader is not looking at. */}
          <details className="border-t border-subtle pt-2">
            <summary className="cursor-pointer text-2xs text-muted">
              Definition, sources and caveats
            </summary>
            <div className="mt-1.5 space-y-1.5 text-2xs text-muted">
              <p>{definition.aggregation}</p>
              <p className="font-mono">
                {definition.code} · gate {definition.permission} · {definition.refresh} ·{' '}
                {envelope.meta.cache_ttl}s cache
              </p>
              {definition.caveats.length > 0 && (
                <ul className="list-disc space-y-0.5 pl-4">
                  {definition.caveats.map((caveat) => (
                    <li key={caveat}>{caveat}</li>
                  ))}
                </ul>
              )}
              <p className="font-mono">
                {envelope.meta.scope} · {envelope.meta.sources.join(', ')}
              </p>
            </div>
          </details>
        </div>
      )}
    </Panel>
  );
}

export default MetricPanel;
