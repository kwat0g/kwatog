/** Sprint 8 — Task 74 + Sprint P5. Self-service: my attendance this month.
 *
 * Card-list rendering optimised for narrow phone viewports. Each card has
 * a colored status dot (present / late / halfday / absent / on_leave /
 * holiday) so workers can glance at the month and find anomalies fast.
 */
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';

interface AttendanceRow {
  id: string;
  date: string;
  time_in: string | null;
  time_out: string | null;
  regular_hours: string | number | null;
  overtime_hours?: string | number | null;
  status?: string;
  is_late?: boolean;
}

const STATUS_VARIANT: Record<string, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  present:  'success',
  late:     'warning',
  halfday:  'warning',
  absent:   'danger',
  on_leave: 'info',
  holiday:  'neutral',
};

function fmtTime(value: string | null): string {
  if (!value) return '—';
  const s = String(value);
  // Backend may return HH:MM:SS, full datetime, or null — handle each.
  if (s.includes('T')) return s.slice(11, 16);
  return s.slice(0, 5);
}

export default function SelfServiceDtrPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'dtr'],
    queryFn: () =>
      client
        .get<{ data: AttendanceRow[]; meta: unknown }>('/attendances', {
          params: { per_page: 100, scope: 'self' },
        })
        .then((r) => r.data),
  });

  if (isLoading) {
    return (
      <div className="px-4 py-4 space-y-2">
        {[1, 2, 3, 4, 5].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}
      </div>
    );
  }
  if (isError) {
    return (
      <div className="px-4 py-6">
        <EmptyState
          icon="alert-circle"
          title="Couldn't load attendance"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  const rows: AttendanceRow[] = data?.data ?? [];
  if (!rows.length) {
    return (
      <div className="px-4 py-6">
        <EmptyState icon="calendar" title="No attendance records this month" />
      </div>
    );
  }

  return (
    <div className="px-4 py-4 space-y-3">
      <div className="flex items-baseline justify-between">
        <h1 className="text-base font-medium">Daily time record</h1>
        <span className="text-xs text-muted font-mono tabular-nums">
          {rows.length} {rows.length === 1 ? 'day' : 'days'}
        </span>
      </div>

      <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
        {rows.slice(0, 31).map((r) => {
          const variant = STATUS_VARIANT[r.status ?? 'present'] ?? 'neutral';
          return (
            <li key={r.id} className="px-3 py-2.5 flex items-center gap-3">
              <span className="font-mono tabular-nums text-sm shrink-0 w-20">
                {r.date ?? '—'}
              </span>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 flex-wrap">
                  <span
                    className={cn(
                      'font-mono tabular-nums text-sm',
                      r.is_late ? 'text-warning-fg' : 'text-muted',
                    )}
                  >
                    {fmtTime(r.time_in)} – {fmtTime(r.time_out)}
                  </span>
                  {r.status && (
                    <Chip variant={variant}>{r.status.replace('_', ' ')}</Chip>
                  )}
                </div>
                {Number(r.overtime_hours ?? 0) > 0 && (
                  <div className="text-2xs text-muted mt-0.5">
                    OT <span className="font-mono tabular-nums">{r.overtime_hours}h</span>
                  </div>
                )}
              </div>
              <span className="font-mono tabular-nums text-sm text-primary shrink-0">
                {Number(r.regular_hours ?? 0).toFixed(2)}h
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
