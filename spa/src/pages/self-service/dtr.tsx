/** Sprint 8 — Task 74 + Sprint P5 + SS-DTR. DTR with month picker. */
import { useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { PageHeader } from '@/components/layout/PageHeader';
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

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

function fmtTime(value: string | null): string {
  if (!value) return '—';
  const s = String(value);
  if (s.includes('T')) return s.slice(11, 16);
  return s.slice(0, 5);
}

function monthRange(year: number, month: number): { from: string; to: string } {
  const from = `${year}-${String(month).padStart(2, '0')}-01`;
  const lastDay = new Date(year, month, 0).getDate();
  const to = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
  return { from, to };
}

export default function SelfServiceDtrPage() {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1); // 1-indexed

  const { from, to } = useMemo(() => monthRange(year, month), [year, month]);

  const isCurrentMonth = year === now.getFullYear() && month === now.getMonth() + 1;
  // Cap how far back the user can go: 13 months (covers prior year same month)
  const minYear = now.getFullYear() - 1;
  const minMonth = now.getMonth() + 1; // same month last year
  const isEarliestMonth = year === minYear && month === minMonth;

  const goBack = () => {
    if (isEarliestMonth) return;
    if (month === 1) { setYear((y) => y - 1); setMonth(12); }
    else { setMonth((m) => m - 1); }
  };
  const goForward = () => {
    if (isCurrentMonth) return;
    if (month === 12) { setYear((y) => y + 1); setMonth(1); }
    else { setMonth((m) => m + 1); }
  };

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['self-service', 'dtr', from, to],
    queryFn: () =>
      client
        .get<{ data: AttendanceRow[]; meta: unknown }>('/attendance/attendances', {
          params: { per_page: 100, scope: 'self', from, to },
        })
        .then((r) => r.data),
  });

  const rows: AttendanceRow[] = data?.data ?? [];

  return (
    <div>
      <PageHeader title="Daily Time Record" backTo="/self-service" backLabel="Dashboard" />

      {/* Month picker */}
      <div className="flex items-center justify-between px-5 py-3 border-b border-default">
        <button
          type="button"
          onClick={goBack}
          disabled={isEarliestMonth}
          className="w-9 h-9 rounded-md flex items-center justify-center hover:bg-elevated disabled:opacity-40"
          aria-label="Previous month"
        >
          <ChevronLeft size={18} />
        </button>
        <span className="text-sm font-medium">
          {MONTH_NAMES[month - 1]} {year}
        </span>
        <button
          type="button"
          onClick={goForward}
          disabled={isCurrentMonth}
          className="w-9 h-9 rounded-md flex items-center justify-center hover:bg-elevated disabled:opacity-40"
          aria-label="Next month"
        >
          <ChevronRight size={18} />
        </button>
      </div>

      <div className="px-5 py-4">
        {isLoading && (
          <div className="space-y-2">
            {[1, 2, 3, 4, 5].map((i) => <SkeletonBlock key={i} className="h-14 rounded-md" />)}
          </div>
        )}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Couldn't load attendance"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}
        {!isLoading && !isError && rows.length === 0 && (
          <EmptyState
            icon="calendar"
            title={`No records for ${MONTH_NAMES[month - 1]} ${year}`}
          />
        )}
        {rows.length > 0 && (
          <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
            {rows.map((row) => (
              <li
                key={row.id}
                className={cn(
                  'px-3 py-2.5',
                  row.status === 'absent' && 'bg-danger/5',
                )}
              >
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <div className="text-sm font-mono tabular-nums font-medium">
                      {row.date}
                    </div>
                    <div className="text-xs text-muted">
                      {fmtTime(row.time_in)} → {fmtTime(row.time_out)}
                      {row.regular_hours != null && (
                        <span className="ml-2 font-mono tabular-nums">
                          {Number(row.regular_hours).toFixed(1)}h
                        </span>
                      )}
                    </div>
                  </div>
                  {row.status && (
                    <Chip variant={STATUS_VARIANT[row.status] ?? 'neutral'}>
                      {row.status.replace(/_/g, ' ')}
                    </Chip>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
