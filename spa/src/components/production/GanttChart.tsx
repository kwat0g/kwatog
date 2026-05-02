/**
 * Sprint 6 — Task 54. Thin React wrapper around frappe-gantt.
 *
 * frappe-gantt is vanilla JS — it mounts onto a DOM element and replaces
 * its contents. We wrap it in a ref-based effect that:
 *   - constructs Gantt with current `tasks`
 *   - rebuilds when `tasks` shape changes
 *   - calls `onClick` when a bar is clicked
 *
 * Bars carry a status-derived custom_class. Color overrides live in
 * styles/globals.css under `.gantt .bar.<variant>` so they read from the
 * design-system tokens (no inline color).
 */
import { useEffect, useRef } from 'react';
// eslint-disable-next-line @typescript-eslint/no-explicit-any
import Gantt from 'frappe-gantt';
import 'frappe-gantt/dist/frappe-gantt.css';
import type { GanttRow } from '@/types/mrp';

type ViewMode = 'Day' | 'Week' | 'Month';

interface Props {
  rows: GanttRow[];
  viewMode?: ViewMode;
  onBarClick?: (woId: string) => void;
}

interface FlatTask {
  id: string;
  name: string;
  start: string;
  end: string;
  progress: number;
  custom_class: string;
  rowLabel: string;
  woId: string | null;
}

const statusClass = (woStatus: string | null): string => {
  switch (woStatus) {
    case 'in_progress':
    case 'confirmed': return 'wo-info';
    case 'paused':    return 'wo-warning';
    case 'completed':
    case 'closed':    return 'wo-success';
    case 'cancelled': return 'wo-danger';
    default:          return 'wo-neutral';
  }
};

export function GanttChart({ rows, viewMode = 'Week', onBarClick }: Props) {
  const ref = useRef<HTMLDivElement | null>(null);
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const ganttRef = useRef<any>(null);

  // Flatten rows → tasks. Prefix bar id with the row label so frappe-gantt
  // treats them as separate lanes; we still map back to the woId on click.
  const tasks: FlatTask[] = rows.flatMap((r) =>
    r.bars.map((b) => ({
      id: `${r.machine_code}-${b.id}`,
      name: `${r.machine_code} · ${b.wo_number ?? '?'}`,
      start: b.start.slice(0, 10),
      end: b.end.slice(0, 10),
      progress: 0,
      custom_class: statusClass(b.wo_status),
      rowLabel: r.machine_code,
      woId: b.wo_id,
    })),
  );

  useEffect(() => {
    const host = ref.current;
    if (!host) return;
    host.innerHTML = '';
    try {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      ganttRef.current = new (Gantt as any)(host, tasks, {
        view_mode: viewMode,
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        on_click: (task: any) => {
          const t = tasks.find((x) => x.id === task.id);
          if (t?.woId && onBarClick) onBarClick(t.woId);
        },
      });
    } catch (err) {
      // eslint-disable-next-line no-console
      console.error('[GanttChart] failed to instantiate frappe-gantt', err);
      host.innerHTML =
        '<div class="text-sm text-muted p-4">Gantt failed to render. Check browser console for details.</div>';
    }
    return () => {
      if (host) host.innerHTML = '';
      ganttRef.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(tasks), viewMode]);

  if (rows.length === 0) {
    return (
      <div className="px-5 py-12 text-center text-sm text-muted">
        Nothing scheduled in this window. Run the scheduler to fill it.
      </div>
    );
  }

  return <div ref={ref} className="frappe-gantt-host overflow-x-auto" />;
}
