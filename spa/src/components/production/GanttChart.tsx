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
import type { GanttRow } from '@/types/mrp';

// frappe-gantt has no published TypeScript types. Declare the bits we use.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
declare const window: any;

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
    if (!ref.current) return;
    // Lazy require to avoid SSR import cost; frappe-gantt is browser-only.
    let Gantt: unknown = null;
    try {
      // eslint-disable-next-line @typescript-eslint/no-require-imports, @typescript-eslint/no-var-requires
      Gantt = (window.Gantt) ?? null;
      if (!Gantt) {
        // dynamic import — safe in modern Vite builds
        import('frappe-gantt').then((mod) => {
          // eslint-disable-next-line @typescript-eslint/no-explicit-any
          const G = (mod as any).default ?? mod;
          if (!ref.current) return;
          ref.current.innerHTML = '';
          ganttRef.current = new G(ref.current, tasks, {
            view_mode: viewMode,
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            on_click: (task: any) => {
              const t = tasks.find((x) => x.id === task.id);
              if (t?.woId && onBarClick) onBarClick(t.woId);
            },
          });
        }).catch(() => {
          if (ref.current) {
            ref.current.innerHTML = '<div class="text-sm text-muted p-4">Gantt library failed to load. Run <code>npm install frappe-gantt</code>.</div>';
          }
        });
        return;
      }
    } catch {
      // Library not installed locally — render a placeholder.
      if (ref.current) {
        ref.current.innerHTML = '<div class="text-sm text-muted p-4">Gantt library not yet installed.</div>';
      }
    }
    return () => {
      if (ref.current) ref.current.innerHTML = '';
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
