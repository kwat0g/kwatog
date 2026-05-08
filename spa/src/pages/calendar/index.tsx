import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Calendar as CalIcon } from 'lucide-react';
import { calendarApi } from '@/api/calendar';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import type {
  CalendarEvent,
  CalendarLayer,
  CalendarEventVariant,
} from '@/types/calendar';

const ALL_LAYERS: { key: CalendarLayer; label: string; variant: CalendarEventVariant }[] = [
  { key: 'holiday',     label: 'Holidays',     variant: 'info' },
  { key: 'leave',       label: 'Leaves',       variant: 'neutral' },
  { key: 'delivery',    label: 'Deliveries',   variant: 'info' },
  { key: 'maintenance', label: 'Maintenance',  variant: 'warning' },
  { key: 'payroll',     label: 'Payroll',      variant: 'success' },
  { key: 'wo_due',      label: 'WO due',       variant: 'warning' },
];

function startOfMonth(d: Date): Date { return new Date(d.getFullYear(), d.getMonth(), 1); }
function endOfMonth(d: Date): Date { return new Date(d.getFullYear(), d.getMonth() + 1, 0); }
function fmtDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}
function monthLabel(d: Date): string {
  return d.toLocaleString('en-US', { month: 'long', year: 'numeric' });
}

/** Build a 6-row × 7-col grid covering the displayed month. */
function buildGrid(month: Date): Date[][] {
  const start = startOfMonth(month);
  const startWeekday = start.getDay(); // 0 = Sunday
  const gridStart = new Date(start);
  gridStart.setDate(start.getDate() - startWeekday);
  const rows: Date[][] = [];
  for (let r = 0; r < 6; r++) {
    const row: Date[] = [];
    for (let c = 0; c < 7; c++) {
      const d = new Date(gridStart);
      d.setDate(gridStart.getDate() + r * 7 + c);
      row.push(d);
    }
    rows.push(row);
  }
  return rows;
}

const VARIANT_CLASS: Record<CalendarEventVariant, string> = {
  success: 'bg-success-bg text-success-fg',
  warning: 'bg-warning-bg text-warning-fg',
  danger:  'bg-danger-bg text-danger-fg',
  info:    'bg-info-bg text-info-fg',
  neutral: 'bg-subtle text-muted',
};

export default function CalendarPage() {
  const navigate = useNavigate();
  const [cursor, setCursor] = useState<Date>(() => startOfMonth(new Date()));
  const [activeLayers, setActiveLayers] = useState<CalendarLayer[]>(
    ALL_LAYERS.map((l) => l.key),
  );
  const [selected, setSelected] = useState<CalendarEvent | null>(null);

  const monthStart = startOfMonth(cursor);
  const monthEnd = endOfMonth(cursor);
  const grid = useMemo(() => buildGrid(monthStart), [monthStart]);
  const fromStr = fmtDate(grid[0][0]);
  const toStr = fmtDate(grid[5][6]);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['calendar', 'events', fromStr, toStr, activeLayers.sort().join(',')],
    queryFn: () => calendarApi.events({ from: fromStr, to: toStr, layers: activeLayers }),
    placeholderData: (prev) => prev,
  });

  const eventsByDay = useMemo(() => {
    const map = new Map<string, CalendarEvent[]>();
    for (const ev of data?.data ?? []) {
      const start = new Date(ev.start);
      const end = new Date(ev.end);
      for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
        const key = fmtDate(d);
        if (!map.has(key)) map.set(key, []);
        map.get(key)!.push(ev);
      }
    }
    return map;
  }, [data]);

  const toggleLayer = (key: CalendarLayer) => {
    setActiveLayers((prev) =>
      prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key],
    );
  };

  const today = fmtDate(new Date());

  return (
    <div>
      <PageHeader
        title="Calendar"
        subtitle={data ? `${data.meta.count} events` : undefined}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="sm" onClick={() => setCursor(startOfMonth(new Date()))}>
              Today
            </Button>
            <div className="flex items-center gap-1">
              <Button
                variant="ghost"
                size="sm"
                aria-label="Previous month"
                onClick={() =>
                  setCursor((c) => new Date(c.getFullYear(), c.getMonth() - 1, 1))
                }
              >
                <ChevronLeft size={16} />
              </Button>
              <span className="font-medium tabular-nums px-2 min-w-[140px] text-center">
                {monthLabel(cursor)}
              </span>
              <Button
                variant="ghost"
                size="sm"
                aria-label="Next month"
                onClick={() =>
                  setCursor((c) => new Date(c.getFullYear(), c.getMonth() + 1, 1))
                }
              >
                <ChevronRight size={16} />
              </Button>
            </div>
          </div>
        }
      />

      {/* Layer toggles */}
      <div className="px-5 py-3 border-b border-default flex flex-wrap items-center gap-2">
        {ALL_LAYERS.map((l) => {
          const active = activeLayers.includes(l.key);
          return (
            <button
              key={l.key}
              type="button"
              onClick={() => toggleLayer(l.key)}
              className={cn(
                'inline-flex items-center gap-1.5 px-2 py-1 rounded text-xs font-medium border transition-colors',
                active
                  ? 'border-default bg-elevated text-primary'
                  : 'border-subtle text-muted hover:bg-elevated',
              )}
              aria-pressed={active}
            >
              <span
                className={cn('inline-block w-2 h-2 rounded-full', VARIANT_CLASS[l.variant])}
                aria-hidden
              />
              {l.label}
            </button>
          );
        })}
      </div>

      {/* States */}
      {isLoading && !data && (
        <div className="px-5 py-4">
          <div className="h-[480px] bg-elevated rounded-md animate-pulse" />
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load calendar"
          description="Something went wrong loading events."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="calendar"
          title="No events this month"
          description="Try toggling more layers or moving to a different month."
        />
      )}

      {data && (
        <div className="px-5 py-4">
          <div className="grid grid-cols-7 text-2xs uppercase tracking-wider text-muted font-medium border-b border-default">
            {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((d) => (
              <div key={d} className="px-2 py-1.5">{d}</div>
            ))}
          </div>
          <div className="grid grid-cols-7 grid-rows-6 border-l border-t border-default">
            {grid.flat().map((d, i) => {
              const key = fmtDate(d);
              const inMonth = d.getMonth() === cursor.getMonth();
              const events = eventsByDay.get(key) ?? [];
              const isToday = key === today;
              return (
                <div
                  key={i}
                  className={cn(
                    'border-r border-b border-default min-h-[96px] p-1.5 flex flex-col gap-1',
                    !inMonth && 'bg-subtle/40',
                  )}
                >
                  <div
                    className={cn(
                      'text-xs font-mono tabular-nums',
                      inMonth ? 'text-primary' : 'text-subtle',
                      isToday && 'inline-flex items-center justify-center bg-accent text-accent-fg w-5 h-5 rounded-full text-2xs',
                    )}
                  >
                    {d.getDate()}
                  </div>
                  <div className="flex flex-col gap-0.5 overflow-hidden">
                    {events.slice(0, 3).map((ev) => (
                      <button
                        key={ev.id}
                        type="button"
                        onClick={() => setSelected(ev)}
                        className={cn(
                          'text-2xs px-1.5 py-0.5 rounded truncate text-left',
                          VARIANT_CLASS[ev.color_variant],
                        )}
                        title={ev.title}
                      >
                        {ev.title}
                      </button>
                    ))}
                    {events.length > 3 && (
                      <span className="text-2xs text-muted font-mono">
                        +{events.length - 3} more
                      </span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Event detail modal */}
      <Modal
        isOpen={!!selected}
        onClose={() => setSelected(null)}
        title="Event"
        size="sm"
      >
        {selected && (
          <div className="space-y-3 text-sm">
            <div className="flex items-center gap-2">
              <Chip variant={selected.color_variant}>{selected.type.replace('_', ' ')}</Chip>
              <span className="font-medium">{selected.title}</span>
            </div>
            <div className="text-xs text-muted font-mono tabular-nums">
              {selected.start === selected.end
                ? selected.start
                : `${selected.start} → ${selected.end}`}
            </div>
            {selected.meta && Object.keys(selected.meta).length > 0 && (
              <dl className="text-xs space-y-1">
                {Object.entries(selected.meta).map(([k, v]) => (
                  <div key={k} className="flex justify-between gap-3">
                    <dt className="text-muted">{k.replace(/_/g, ' ')}</dt>
                    <dd className="text-secondary">{String(v)}</dd>
                  </div>
                ))}
              </dl>
            )}
            <div className="flex justify-end gap-2 pt-3 border-t border-default">
              <Button variant="secondary" size="sm" onClick={() => setSelected(null)}>
                Close
              </Button>
              <Button
                variant="primary"
                size="sm"
                icon={<CalIcon size={14} />}
                onClick={() => {
                  navigate(selected.link);
                  setSelected(null);
                }}
              >
                Open record
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
