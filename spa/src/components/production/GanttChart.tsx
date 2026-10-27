import { useEffect, useMemo, useRef, useState } from 'react';
import { EmptyState } from '@/components/ui/EmptyState';
import { cn } from '@/lib/cn';
import { horizontalScrollEdges } from '@/lib/horizontalScrollEdges';
import { focusRing } from '@/lib/focus';
import type { GanttRow } from '@/types/mrp';
import { scheduleDateKey, scheduleDate, scheduleTime } from './scheduleDates';

type ViewMode = 'Day' | 'Week' | 'Month';
interface Props {
  rows: GanttRow[];
  viewMode?: ViewMode;
  emptyWindowDays?: number;
  from?: string;
  to?: string;
  timezone?: string;
  onBarClick?: (woId: string) => void;
  fillHeight?: boolean;
  className?: string;
}
const DAY_MS = 86_400_000;
function dayKey(iso: string, timezone: string): number {
  return Date.parse(`${scheduleDateKey(iso, timezone)}T00:00:00Z`);
}

export function GanttChart({
  rows,
  viewMode = 'Week',
  emptyWindowDays = 14,
  from,
  to,
  timezone = 'Asia/Manila',
  onBarClick,
  fillHeight = false,
  className,
}: Props) {
  const scrollerRef = useRef<HTMLDivElement>(null);
  const [scrollEdges, setScrollEdges] = useState({ left: false, right: false });
  const populatedRows = rows.filter((row) => row.bars.length > 0);
  const { startDay, days } = useMemo(() => {
    const bars = rows.flatMap((row) => row.bars);
    const start = from
      ? dayKey(from, timezone)
      : bars.length
        ? Math.min(...bars.map((bar) => dayKey(bar.start, timezone))) - DAY_MS
        : dayKey(new Date().toISOString(), timezone);
    const end = to
      ? dayKey(to, timezone)
      : bars.length
        ? Math.max(...bars.map((bar) => dayKey(bar.end, timezone))) + 2 * DAY_MS
        : start + emptyWindowDays * DAY_MS;
    return { startDay: start, days: Math.max(1, Math.ceil((end - start) / DAY_MS)) };
  }, [rows, from, to, timezone, emptyWindowDays]);
  const labelWidth = 248;
  const columnWidth = viewMode === 'Day' ? 200 : viewMode === 'Month' ? 54 : 108;
  const columns = `${labelWidth}px repeat(${days}, minmax(${columnWidth}px, 1fr))`;

  useEffect(() => {
    const scroller = scrollerRef.current;
    if (!scroller) return;
    const update = () => {
      const next = horizontalScrollEdges(scroller);
      setScrollEdges((current) => current.left === next.left && current.right === next.right ? current : next);
    };
    update();
    scroller.addEventListener('scroll', update, { passive: true });
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(update);
    observer?.observe(scroller);
    if (scroller.firstElementChild) observer?.observe(scroller.firstElementChild);
    return () => {
      scroller.removeEventListener('scroll', update);
      observer?.disconnect();
    };
  }, [columns, populatedRows.length]);

  if (!populatedRows.length)
    return (
      <EmptyState
        icon="calendar"
        title="No schedules match these filters"
        description="Change the date range or clear the filters to see saved schedules."
      />
    );

  return (
    <div
      className={cn('scroll-edge-frame min-w-0', fillHeight && 'min-h-0 flex-1', className)}
      data-more-left={scrollEdges.left}
      data-more-right={scrollEdges.right}
    >
      <div
        ref={scrollerRef}
        data-layout="schedule-scroll-region"
        className={cn('table-scroll-container overflow-auto', fillHeight && 'h-full min-h-0')}
        role="region"
        aria-label="Production timeline. Scroll to review dates and work orders."
        tabIndex={0}
      >
      <div style={{ minWidth: labelWidth + days * columnWidth }}>
        <div
          data-layout="gantt-header"
          className="sticky top-0 z-30 grid border-b border-default bg-surface"
          style={{ gridTemplateColumns: columns }}
        >
          <div className="sticky left-0 z-40 bg-surface px-4 py-3 text-table-header font-semibold">
            Machine / work order
          </div>
          {Array.from({ length: days }, (_, index) => {
            const date = new Date(startDay + index * DAY_MS);
            return (
              <div key={index} className="border-l border-subtle py-2 text-center text-caption text-secondary">
                <div className="font-semibold tabular-nums">
                  {date.toLocaleDateString('en-PH', {
                    timeZone: 'UTC',
                    month: 'short',
                    day: '2-digit',
                  })}
                </div>
                <div className="text-caption text-muted">
                  {date.toLocaleDateString('en-PH', { timeZone: 'UTC', weekday: 'short' })}
                </div>
              </div>
            );
          })}
        </div>
        {populatedRows.map((row) => (
          <div key={row.machine_id}>
            <div
              className="grid border-b border-default bg-surface"
              style={{ gridTemplateColumns: columns }}
            >
              <div className="sticky left-0 z-20 flex items-baseline gap-2 bg-surface px-4 py-2">
                <span className="font-mono text-sm font-medium">{row.machine_code}</span>
                <span className="text-caption text-muted">{row.bars.length} WOs</span>
              </div>
              <div
                style={{ gridColumn: `2 / span ${days}` }}
                className="truncate px-3 py-2 text-sm text-secondary"
                title={row.name}
              >
                {row.name}
              </div>
            </div>
            {row.bars.map((bar) => {
              const startIndex = (dayKey(bar.start, timezone) - startDay) / DAY_MS;
              const endIndex =
                (dayKey(new Date(Date.parse(bar.end) - 1).toISOString(), timezone) - startDay) /
                  DAY_MS +
                1;
              const left = Math.max(0, startIndex);
              const right = Math.min(days, endIndex);
              const clickable = !!bar.wo_id && !!onBarClick;
              const date = scheduleDateKey(bar.start, timezone);
              return (
                <div
                  key={bar.id}
                  data-layout="gantt-bar-row"
                  className="grid border-b border-subtle hover:bg-subtle"
                  style={{ gridTemplateColumns: columns }}
                >
                  <div className="sticky left-0 z-10 min-w-0 bg-canvas px-4 py-2">
                    <div className="truncate font-mono text-sm" title={bar.wo_number ?? undefined}>
                      {bar.wo_number ?? 'Work order unavailable'}
                    </div>
                    <div
                      className="truncate text-caption text-secondary"
                      title={[bar.product_part_number, bar.product_name, bar.mold_code].filter(Boolean).join(' · ')}
                    >
                      {bar.product_part_number ?? bar.product_name ?? 'Product unavailable'}
                      {bar.mold_code ? ` · ${bar.mold_code}` : ''}
                    </div>
                  </div>
                  <div className="relative min-h-16" style={{ gridColumn: `2 / span ${days}` }}>
                    <div
                      className="absolute inset-0 grid"
                      style={{ gridTemplateColumns: `repeat(${days}, 1fr)` }}
                      aria-hidden
                    >
                      {Array.from({ length: days }, (_, index) => (
                        <div key={index} className="border-l border-subtle" />
                      ))}
                    </div>
                    {right > left && (
                      <button
                        type="button"
                        disabled={!clickable}
                        onClick={() => bar.wo_id && onBarClick?.(bar.wo_id)}
                        data-start-date={date}
                        aria-label={`${bar.wo_number ?? 'Work order'}: ${scheduleDate(bar.start, timezone)}, ${scheduleTime(bar.start, timezone)} to ${scheduleTime(bar.end, timezone)}, ${bar.status === 'pending' ? 'awaiting approval' : bar.status}`}
                        title={`${bar.wo_number} · ${bar.product_name ?? ''} · ${bar.mold_code ?? ''} · ${scheduleDate(bar.start, timezone)} ${scheduleTime(bar.start, timezone)} – ${scheduleDate(bar.end, timezone)} ${scheduleTime(bar.end, timezone)} (${timezone})`}
                        className={cn(
                          'absolute top-2 bottom-2 min-h-11 rounded border px-2 text-left text-sm',
                          focusRing,
                          bar.status === 'pending'
                            ? 'border-warning bg-warning-bg text-warning-fg'
                            : ['completed', 'closed'].includes(bar.wo_status ?? '')
                              ? 'border-success bg-success-bg text-success-fg'
                              : 'border-info bg-info-bg text-info-fg',
                          clickable && 'cursor-pointer hover:brightness-95',
                        )}
                        style={{
                          left: `calc(${(left / days) * 100}% + 3px)`,
                          width: `calc(${((right - left) / days) * 100}% - 6px)`,
                        }}
                      >
                        <span className="block truncate tabular-nums">
                          {scheduleTime(bar.start, timezone)}–{scheduleTime(bar.end, timezone)}
                        </span>
                        <span className="block truncate text-caption">
                          {bar.status === 'pending'
                            ? 'Awaiting approval'
                            : (bar.wo_status?.replaceAll('_', ' ') ?? bar.status)}
                        </span>
                      </button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        ))}
      </div>
      </div>
    </div>
  );
}
