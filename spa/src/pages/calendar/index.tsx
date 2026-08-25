import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuChevronLeft, LuChevronRight, LuCalendar as CalIcon } from '@/lib/icons';
import { calendarApi } from '@/api/calendar';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { ToggleChip } from '@/components/ui/SegmentedControl';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import { focusRingInset } from '@/lib/focus';
import type {
 CalendarEvent,
 CalendarLayer,
 CalendarEventVariant,
} from '@/types/calendar';

function startOfMonth(d: Date): Date { return new Date(d.getFullYear(), d.getMonth(), 1); }
function fmtDate(d: Date): string {
 const y = d.getFullYear();
 const m = String(d.getMonth() + 1).padStart(2, '0');
 const day = String(d.getDate()).padStart(2, '0');
 return `${y}-${m}-${day}`;
}

/** Date-only API values are calendar dates, not UTC instants. */
function parseCalendarDate(value: string): Date {
 const [year, month, day] = value.slice(0, 10).split('-').map(Number);
 return new Date(year, month - 1, day);
}

function dateLabel(d: Date): string {
 return d.toLocaleDateString('en-US', {
 weekday: 'long',
 month: 'long',
 day: 'numeric',
 year: 'numeric',
 });
}

function eventDateLabel(event: CalendarEvent): string {
 const start = parseCalendarDate(event.start);
 const end = parseCalendarDate(event.end);
 return event.start === event.end
 ? dateLabel(start)
 : `${dateLabel(start)} to ${dateLabel(end)}`;
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
 danger: 'bg-danger-bg text-danger-fg',
 info: 'bg-info-bg text-info-fg',
 neutral: 'bg-subtle text-muted',
};

export default function CalendarPage() {
 const navigate = useNavigate();
 const [cursor, setCursor] = useState<Date>(() => startOfMonth(new Date()));
 const [activeLayers, setActiveLayers] = useState<CalendarLayer[]>([]);
 const [departmentId, setDepartmentId] = useState<string>('');
 const initializedLayers = useRef(false);
 const [selected, setSelected] = useState<CalendarEvent | null>(null);
 const [selectedDay, setSelectedDay] = useState<string | null>(null);

 const {
 data: layerOptions,
 isLoading: isLoadingOptions,
 isError: isOptionsError,
 refetch: refetchOptions,
 } = useQuery({
 queryKey: ['calendar', 'options'],
 queryFn: calendarApi.options,
 staleTime: 5 * 60_000,
 });
 useEffect(() => {
 if (!initializedLayers.current && layerOptions?.layers.length) {
 initializedLayers.current = true;
 setActiveLayers(layerOptions.layers.map((layer) => layer.value));
 }
 }, [layerOptions]);

 useEffect(() => {
 if (departmentId && !(layerOptions?.departments ?? []).some((department) => department.value === departmentId)) {
 setDepartmentId('');
 }
 }, [departmentId, layerOptions]);

 const monthStart = startOfMonth(cursor);
 const grid = useMemo(() => buildGrid(monthStart), [monthStart]);
 const fromStr = fmtDate(grid[0][0]);
 const toStr = fmtDate(grid[5][6]);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['calendar', 'events', fromStr, toStr, [...activeLayers].sort().join(','), departmentId],
 queryFn: () => calendarApi.events({
 from: fromStr,
 to: toStr,
 layers: activeLayers,
 department_id: departmentId || undefined,
 }),
 enabled: !!layerOptions && initializedLayers.current,
 placeholderData: (prev) => prev,
 });

 const eventsByDay = useMemo(() => {
 const map = new Map<string, CalendarEvent[]>();
 for (const ev of data?.data ?? []) {
 const start = parseCalendarDate(ev.start);
 const end = parseCalendarDate(ev.end);
 for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
 const key = fmtDate(d);
 if (!map.has(key)) map.set(key, []);
 map.get(key)!.push(ev);
 }
 }
 return map;
 }, [data]);

 const selectedDayEvents = selectedDay ? eventsByDay.get(selectedDay) ?? [] : [];

 const truncatedLayerLabels = useMemo(() => {
 if (!data) return [];
 return Object.entries(data.meta.layer_counts)
 .filter(([, count]) => count.truncated)
 .map(([value]) => layerOptions?.layers.find((layer) => layer.value === value)?.label ?? value);
 }, [data, layerOptions]);

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
 <LuChevronLeft size={16} />
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
 <LuChevronRight size={16} />
 </Button>
 </div>
 </div>
 }
 />

 {/* Options are loaded separately so a failed permissions/options request is
     explicit instead of looking like an empty calendar. */}
 {isOptionsError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load calendar options"
 description="The calendar filters could not be loaded. Try again to continue."
 action={<Button variant="secondary" onClick={() => refetchOptions()}>Retry</Button>}
 />
 )}

 {!isOptionsError && layerOptions && (
 <div className="px-5 py-3 border-b border-default flex flex-wrap items-end gap-3">
 <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Calendar layers">
 {(layerOptions.layers ?? []).map((layer) => {
 const active = activeLayers.includes(layer.value);
 return (
 <ToggleChip
 key={layer.value}
 active={active}
 onClick={() => toggleLayer(layer.value)}
 ariaLabel={`Toggle ${layer.label} layer`}
 className="min-h-11"
 >
 <span
 className={cn('inline-block w-2 h-2 rounded-full', VARIANT_CLASS[layer.variant])}
 aria-hidden
 />
 {layer.label}
 </ToggleChip>
 );
 })}
 </div>
 {layerOptions.departments.length > 0 && (
 <Select
 label="Department"
 value={departmentId}
 onChange={(event) => setDepartmentId(event.target.value)}
 containerClassName="w-52"
 >
 <option value="">All accessible departments</option>
 {layerOptions.departments.map((department) => (
 <option key={department.value} value={department.value}>{department.label}</option>
 ))}
 </Select>
 )}
 </div>
 )}

 {truncatedLayerLabels.length > 0 && (
 <div className="px-5 py-2 text-xs text-warning-fg bg-warning-bg border-b border-warning" role="status">
 Some results are omitted because the range is dense ({truncatedLayerLabels.join(', ')}). Narrow the date range to see all events.
 </div>
 )}

 {isLoadingOptions && !layerOptions && (
 <div className="px-5 py-3 border-b border-default text-sm text-muted" role="status">
 Loading calendar filters…
 </div>
 )}

 {/* States */}
 {!isOptionsError && isLoading && !data && (
 <div className="px-5 py-4">
 <div className="h-[480px] bg-elevated rounded-md animate-pulse" />
 </div>
 )}

 {!isOptionsError && isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load calendar"
 description="Something went wrong loading events."
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {!isOptionsError && data && data.data.length === 0 && (
 <EmptyState
 icon="calendar"
 title="No events this month"
 description="Try toggling more layers or moving to a different month."
 />
 )}

 {!isOptionsError && data && (
 <div className="px-5 py-4">
 <div className="overflow-x-auto pb-2">
 <div className="min-w-[700px]">
 <div role="grid" aria-label={`Calendar for ${monthLabel(cursor)}`}>
 <div role="row" className="grid grid-cols-7 text-2xs uppercase tracking-wider text-muted font-medium border-b border-default">
 {['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].map((day) => (
 <div key={day} role="columnheader" className="px-2 py-1.5">{day.slice(0, 3)}</div>
 ))}
 </div>
 <div className="border-l border-t border-default" role="rowgroup">
 {grid.map((row, rowIndex) => (
 <div key={rowIndex} role="row" className="grid grid-cols-7">
 {row.map((d) => {
 const key = fmtDate(d);
 const inMonth = d.getMonth() === cursor.getMonth();
 const events = eventsByDay.get(key) ?? [];
 const isToday = key === today;
 const cellLabel = `${dateLabel(d)}${isToday ? ', today' : ''}${events.length ? `, ${events.length} event${events.length === 1 ? '' : 's'}` : ''}`;
 return (
 <div
 key={key}
 role="gridcell"
 aria-label={cellLabel}
 aria-current={isToday ? 'date' : undefined}
 className={cn(
 'border-r border-b border-default min-h-[132px] p-1.5 flex flex-col gap-1',
 !inMonth && 'bg-subtle/40',
 )}
 >
 <div
 aria-hidden="true"
 className={cn(
 'text-xs font-mono tabular-nums',
 inMonth ? 'text-primary' : 'text-text-subtle',
 isToday && 'inline-flex items-center justify-center bg-accent text-accent-fg w-6 h-6 rounded-full text-2xs',
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
 'min-h-11 text-2xs px-2 py-1 rounded text-left cursor-pointer line-clamp-2',
 focusRingInset,
 VARIANT_CLASS[ev.color_variant],
 )}
 aria-label={`${ev.type_label ?? ev.type.replace('_', ' ')}: ${ev.title}, ${eventDateLabel(ev)}`}
 title={ev.title}
 >
 {ev.title}
 </button>
 ))}
 {events.length > 3 && (
 <button
 type="button"
 className={cn('min-h-11 text-2xs text-muted font-mono text-left px-2 py-1 rounded hover:bg-elevated cursor-pointer', focusRingInset)}
 onClick={() => setSelectedDay(key)}
 aria-label={`Show ${events.length - 3} more events for ${dateLabel(d)}`}
 >
 +{events.length - 3} more
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
 </div>
 </div>
 )}

 {/* Overflow details keep dense days fully operable. */}
 <Modal
 isOpen={!!selectedDay}
 onClose={() => setSelectedDay(null)}
 title={selectedDay ? `Events on ${dateLabel(parseCalendarDate(selectedDay))}` : 'Events'}
 size="sm"
 >
 <div className="space-y-2" role="list">
 {selectedDayEvents.map((ev) => (
 <div key={ev.id} role="listitem">
 <button
 type="button"
 className={cn('w-full min-h-11 rounded px-3 py-2 text-left flex items-center justify-between gap-3', VARIANT_CLASS[ev.color_variant], focusRingInset)}
 onClick={() => {
 setSelected(ev);
 setSelectedDay(null);
 }}
 aria-label={`${ev.type_label ?? ev.type.replace('_', ' ')}: ${ev.title}, ${eventDateLabel(ev)}`}
 >
 <span className="min-w-0 truncate">{ev.title}</span>
 <span className="shrink-0 text-2xs uppercase">{ev.type_label ?? ev.type.replace('_', ' ')}</span>
 </button>
 </div>
 ))}
 </div>
 </Modal>

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
 <Chip variant={selected.color_variant}>{selected.type_label ?? selected.type.replace('_', ' ')}</Chip>
 <span className="font-medium">{selected.title}</span>
 </div>
 <div className="text-xs text-muted font-mono tabular-nums">
 {eventDateLabel(selected)}
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
 <ModalFooter>
 <Button variant="secondary" size="sm" onClick={() => setSelected(null)}>
 Close
 </Button>
 {selected.link ? (
 <Button
 variant="primary"
 size="sm"
 icon={<CalIcon size={14} />}
 onClick={() => {
 navigate(selected.link!);
 setSelected(null);
 }}
 >
 Open record
 </Button>
 ) : (
 <span className="text-xs text-muted">Record details are not available from this view.</span>
 )}
 </ModalFooter>
 </div>
 )}
 </Modal>
 </div>
 );
}
