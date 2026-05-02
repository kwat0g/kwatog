/**
 * Sprint 6 — Task 54. Native CSS-grid Gantt chart.
 *
 * No external library. We render the Gantt as a CSS grid where each column
 * is a day in the visible window. Bars are positioned by start/end day
 * indexes via `gridColumn`. Status drives the bar color via design-system
 * accent tokens (no inline color).
 *
 * This trades a few features (zoom presets, drag-to-reschedule) for zero
 * dependencies, full design-system compliance, and predictable rendering.
 * Drag-reschedule is a Sprint 7 nice-to-have anyway.
 */
import { useMemo } from 'react';
import { cn } from '@/lib/cn';
import type { GanttRow } from '@/types/mrp';

type ViewMode = 'Day' | 'Week' | 'Month';

interface Props {
  rows: GanttRow[];
  viewMode?: ViewMode;
  onBarClick?: (woId: string) => void;
}

/** ms-per-day, used everywhere. */
const DAY_MS = 24 * 60 * 60 * 1000;

/** Strip a date down to start-of-day UTC for stable diff math. */
function dayKey(iso: string): number {
  const d = new Date(iso);
  return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate());
}

function fmtDay(t: number): string {
  return new Date(t).toLocaleDateString('en-PH', { month: 'short', day: '2-digit' });
}

function fmtMonth(t: number): string {
  return new Date(t).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' });
}

/** Map a WO status to a design-system accent class. */
function barClass(woStatus: string | null): string {
  switch (woStatus) {
    case 'in_progress':
    case 'confirmed':
      return 'bg-info-subtle text-info-fg border-info';
    case 'paused':
      return 'bg-warning-subtle text-warning-fg border-warning';
    case 'completed':
    case 'closed':
      return 'bg-success-subtle text-success-fg border-success';
    case 'cancelled':
      return 'bg-danger-subtle text-danger-fg border-danger';
    default:
      return 'bg-elevated text-primary border-default';
  }
}

export function GanttChart({ rows, viewMode = 'Week', onBarClick }: Props) {
  /**
   * Compute the visible window from the data: earliest start to latest end.
   * If data is empty, fall back to "today + 14 days" so the chart still
   * lays out a header instead of collapsing.
   */
  const { startDay, days } = useMemo(() => {
    const allBars = rows.flatMap((r) => r.bars);
    if (allBars.length === 0) {
      const today = dayKey(new Date().toISOString());
      return { startDay: today, days: 14 };
    }
    const starts = allBars.map((b) => dayKey(b.start));
    const ends = allBars.map((b) => dayKey(b.end));
    const min = Math.min(...starts);
    const max = Math.max(...ends);
    // Pad +1 day on each side for visual breathing room.
    const start = min - DAY_MS;
    const span = Math.max(7, Math.round((max - start) / DAY_MS) + 2);
    return { startDay: start, days: span };
  }, [rows]);

  /** Column width in px — tighter for Month, wider for Day. */
  const colW = viewMode === 'Day' ? 56 : viewMode === 'Month' ? 18 : 28;

  /** Build the header date strip. */
  const header = useMemo(() => {
    const out: { day: number; label: string; isMonthBoundary: boolean }[] = [];
    for (let i = 0; i < days; i++) {
      const t = startDay + i * DAY_MS;
      const d = new Date(t);
      const isMonthBoundary = d.getUTCDate() === 1 || i === 0;
      out.push({ day: i, label: viewMode === 'Month' ? fmtMonth(t) : fmtDay(t), isMonthBoundary });
    }
    return out;
  }, [days, startDay, viewMode]);

  if (rows.length === 0) {
    return (
      <div className="px-5 py-12 text-center text-sm text-muted">
        Nothing scheduled in this window. Run the scheduler to fill it.
      </div>
    );
  }

  const labelW = 160;

  return (
    <div className="overflow-x-auto">
      <div style={{ minWidth: labelW + days * colW }}>
        {/* Header strip */}
        <div className="grid border-b border-default" style={{ gridTemplateColumns: `${labelW}px repeat(${days}, ${colW}px)` }}>
          <div className="px-3 py-2 text-2xs uppercase tracking-wider text-muted font-medium">Machine</div>
          {header.map((h) => (
            <div
              key={h.day}
              className={cn(
                'py-2 text-2xs font-mono text-center text-muted border-l border-subtle',
                h.isMonthBoundary && 'text-primary font-medium',
              )}
              style={viewMode === 'Month' && !h.isMonthBoundary ? { visibility: 'hidden' } : undefined}
            >
              {h.label}
            </div>
          ))}
        </div>

        {/* Rows */}
        {rows.map((row) => (
          <div
            key={row.machine_id}
            className="grid border-b border-subtle hover:bg-subtle"
            style={{ gridTemplateColumns: `${labelW}px repeat(${days}, ${colW}px)` }}
          >
            <div className="px-3 py-2 flex flex-col justify-center">
              <div className="font-mono text-xs">{row.machine_code}</div>
              <div className="text-2xs text-muted truncate">{row.name}</div>
            </div>

            {/* The day cells render the grid background; bars are absolutely
                positioned over them via gridColumn. */}
            <div
              className="relative h-9 col-span-full"
              style={{ gridColumn: `2 / span ${days}` }}
            >
              {/* day cell borders */}
              <div
                className="absolute inset-0 grid"
                style={{ gridTemplateColumns: `repeat(${days}, ${colW}px)` }}
              >
                {Array.from({ length: days }, (_, i) => (
                  <div key={i} className="border-l border-subtle" />
                ))}
              </div>

              {/* bars */}
              {row.bars.map((bar) => {
                const startIdx = Math.max(0, Math.round((dayKey(bar.start) - startDay) / DAY_MS));
                const endIdx = Math.max(startIdx + 1, Math.round((dayKey(bar.end) - startDay) / DAY_MS) + 1);
                const left = startIdx * colW + 2;
                const width = Math.max(colW * 0.6, (endIdx - startIdx) * colW - 4);
                const clickable = !!bar.wo_id && !!onBarClick;
                return (
                  <button
                    key={bar.id}
                    type="button"
                    onClick={() => clickable && bar.wo_id && onBarClick!(bar.wo_id)}
                    disabled={!clickable}
                    className={cn(
                      'absolute top-1 bottom-1 px-2 rounded-sm border text-2xs font-mono truncate text-left',
                      barClass(bar.wo_status),
                      clickable ? 'cursor-pointer hover:brightness-110' : 'cursor-default',
                    )}
                    style={{ left, width }}
                    title={
                      `${bar.wo_number ?? '—'}\n` +
                      `${bar.product_name ?? ''}\n` +
                      `${bar.start} → ${bar.end}\n` +
                      `Status: ${bar.wo_status ?? bar.status}`
                    }
                  >
                    <span className="font-medium">{bar.wo_number ?? '—'}</span>
                    {bar.mold_code && <span className="text-muted ml-1">· {bar.mold_code}</span>}
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
