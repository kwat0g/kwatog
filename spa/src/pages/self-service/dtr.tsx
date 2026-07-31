/** Sprint 8 — Task 74 + Sprint P5 + SS-DTR. DTR with month picker. */
import { useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { PageHeader } from '@/components/layout/PageHeader';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatDate } from '@/lib/formatDate';

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

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

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
  if (s.includes('T')) return s.slice(11, 16);
  return s.slice(0, 5);
}

function fmtHours(value: string | number | null | undefined): string {
  if (value == null || value === '') return '—';
  const n = Number(value);
  if (Number.isNaN(n) || n === 0) return '—';
  return n.toFixed(1);
}

function monthRange(year: number, month: number): { from: string; to: string } {
  const from = `${year}-${String(month).padStart(2, '0')}-01`;
  const lastDay = new Date(year, month, 0).getDate();
  const to = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
  return { from, to };
}

const columns: Column<AttendanceRow>[] = [
  {
    key: 'date',
    header: 'Date',
    cell: (r) => <NumCell>{formatDate(r.date)}</NumCell>,
  },
  {
    key: 'time_in',
    header: 'Time in',
    cell: (r) => <NumCell>{fmtTime(r.time_in)}</NumCell>,
  },
  {
    key: 'time_out',
    header: 'Time out',
    cell: (r) => <NumCell>{fmtTime(r.time_out)}</NumCell>,
  },
  {
    key: 'regular_hours',
    header: 'Regular hrs',
    align: 'right',
    cell: (r) => <NumCell>{fmtHours(r.regular_hours)}</NumCell>,
  },
  {
    key: 'overtime_hours',
    header: 'OT hrs',
    align: 'right',
    cell: (r) => <NumCell>{fmtHours(r.overtime_hours)}</NumCell>,
  },
  {
    key: 'status',
    header: 'Status',
    cell: (r) =>
      r.status ? (
        <Chip variant={STATUS_VARIANT[r.status] ?? 'neutral'}>
          {r.status.replace(/_/g, ' ')}
        </Chip>
      ) : (
        '—'
      ),
  },
];

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
    placeholderData: (prev) => prev,
  });

  const rows: AttendanceRow[] = data?.data ?? [];

  return (
    <div>
      <PageHeader
        title="Daily Time Record"
        subtitle={`${MONTH_NAMES[month - 1]} ${year}`}
        actions={
          <div className="flex items-center gap-1.5">
            <Button
              variant="secondary"
              size="sm"
              iconOnly
              icon={<ChevronLeft size={14} />}
              aria-label="Previous month"
              onClick={goBack}
              disabled={isEarliestMonth}
            />
            <span className="text-sm font-mono tabular-nums text-primary w-32 text-center select-none">
              {MONTH_NAMES[month - 1]} {year}
            </span>
            <Button
              variant="secondary"
              size="sm"
              iconOnly
              icon={<ChevronRight size={14} />}
              aria-label="Next month"
              onClick={goForward}
              disabled={isCurrentMonth}
            />
          </div>
        }
      />

      <div className="px-5 py-4">
        {isLoading && !data && <SkeletonTable columns={6} rows={10} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Couldn't load attendance"
            description="An error occurred while loading your records. Please try again."
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && rows.length === 0 && (
          <EmptyState
            icon="calendar"
            title={`No records for ${MONTH_NAMES[month - 1]} ${year}`}
            description="Attendance entries appear here once your biometric logs are processed."
          />
        )}

        {rows.length > 0 && (
          <DataTable columns={columns} data={rows} stickyHeader={false} />
        )}
      </div>
    </div>
  );
}
