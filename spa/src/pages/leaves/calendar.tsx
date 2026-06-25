import { useState, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { leaveCalendarApi } from '@/api/leave';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Tooltip } from '@/components/ui/Tooltip';
import { PageHeader } from '@/components/layout/PageHeader';
import type { LeaveCalendarDay } from '@/types/leave';
import { cn } from '@/lib/cn';

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;
const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
] as const;

function coverageColor(pct: number): string {
  if (pct > 80) return 'bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-800';
  if (pct >= 60) return 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800';
  return 'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800';
}

function coverageTextColor(pct: number): string {
  if (pct > 80) return 'text-emerald-700 dark:text-emerald-400';
  if (pct >= 60) return 'text-amber-700 dark:text-amber-400';
  return 'text-red-700 dark:text-red-400';
}

export default function LeaveCalendarPage() {
  const now = new Date();
  const [year, setYear] = useState(now.getFullYear());
  const [month, setMonth] = useState(now.getMonth() + 1);
  const [departmentId, setDepartmentId] = useState<string>('');
  const [selectedDay, setSelectedDay] = useState<LeaveCalendarDay | null>(null);

  const { data: departments } = useQuery({
    queryKey: ['departments-tree'],
    queryFn: () => departmentsApi.tree(),
    staleTime: 5 * 60 * 1000,
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['leave-calendar', year, month, departmentId],
    queryFn: () => leaveCalendarApi.index({
      year,
      month,
      ...(departmentId ? { department_id: departmentId } : {}),
    }),
  });

  const prevMonth = () => {
    if (month === 1) { setYear(year - 1); setMonth(12); }
    else setMonth(month - 1);
  };
  const nextMonth = () => {
    if (month === 12) { setYear(year + 1); setMonth(1); }
    else setMonth(month + 1);
  };

  // Build the grid: pad the start with empty cells so the first day aligns with its weekday
  const gridCells = useMemo(() => {
    if (!data?.days?.length) return [];
    const firstDow = data.days[0].day_of_week;
    const padding: (LeaveCalendarDay | null)[] = Array(firstDow).fill(null);
    return [...padding, ...data.days];
  }, [data]);

  return (
    <div>
      <PageHeader
        title="Leave calendar"
        subtitle={data ? `${data.headcount} active employees${departmentId ? ' in department' : ''}` : undefined}
        backTo="/hr/leaves"
        backLabel="Leave requests"
        refreshingQueryKey={['leave-calendar', year, month, departmentId]}
      />

      {/* Filters */}
      <div className="px-5 py-3 border-b border-default flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1">
          <Button variant="secondary" size="sm" onClick={prevMonth} aria-label="Previous month">
            <ChevronLeft size={14} />
          </Button>
          <span className="text-sm font-medium min-w-[140px] text-center">
            {MONTH_NAMES[month - 1]} {year}
          </span>
          <Button variant="secondary" size="sm" onClick={nextMonth} aria-label="Next month">
            <ChevronRight size={14} />
          </Button>
        </div>
        <Select
          value={departmentId}
          onChange={(e) => setDepartmentId(e.target.value)}
          containerClassName="w-48"
        >
          <option value="">All departments</option>
          {departments?.map((d) => (
            <option key={d.id} value={d.id}>{d.name}</option>
          ))}
        </Select>
        <Button
          variant="secondary"
          size="sm"
          onClick={() => { setYear(now.getFullYear()); setMonth(now.getMonth() + 1); setDepartmentId(''); }}
        >
          Today
        </Button>
      </div>

      {/* Loading skeleton */}
      {isLoading && (
        <div className="px-5 py-4">
          <div className="grid grid-cols-7 gap-1">
            {Array.from({ length: 35 }).map((_, i) => (
              <SkeletonBlock key={i} className="h-20 rounded-md" />
            ))}
          </div>
        </div>
      )}

      {/* Error state */}
      {isError && (
        <div className="px-5 py-8">
          <EmptyState
            icon="alert-circle"
            title="Failed to load calendar"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        </div>
      )}

      {/* Calendar grid */}
      {data && (
        <div className="px-5 py-4">
          {/* Day of week headers */}
          <div className="grid grid-cols-7 gap-1 mb-1">
            {DAY_LABELS.map((d) => (
              <div key={d} className="text-center text-xs text-muted font-medium py-1">
                {d}
              </div>
            ))}
          </div>

          {/* Cells */}
          <div className="grid grid-cols-7 gap-1">
            {gridCells.map((cell, i) =>
              cell === null ? (
                <div key={`pad-${i}`} className="h-20" />
              ) : (
                <Tooltip
                  key={cell.date}
                  side="bottom"
                  content={
                    cell.employees_on_leave.length > 0 ? (
                      <div className="max-w-[200px] text-left whitespace-normal">
                        {cell.employees_on_leave.map((e, j) => (
                          <div key={j} className="truncate">
                            {e.employee_name} ({e.leave_type}) - {e.status.replace('_', ' ')}
                          </div>
                        ))}
                      </div>
                    ) : 'No leaves'
                  }
                >
                  <button
                    type="button"
                    onClick={() => setSelectedDay(selectedDay?.date === cell.date ? null : cell)}
                    className={cn(
                      'h-20 w-full rounded-md border p-1.5 text-left transition-colors cursor-pointer',
                      'hover:ring-2 hover:ring-accent/30',
                      cell.date === new Date().toISOString().split('T')[0]
                        ? 'ring-2 ring-accent'
                        : '',
                      selectedDay?.date === cell.date
                        ? 'ring-2 ring-accent'
                        : '',
                      cell.approved_count > 0 || cell.pending_count > 0
                        ? coverageColor(cell.coverage_pct)
                        : 'bg-canvas border-default',
                    )}
                  >
                    <div className="flex items-start justify-between">
                      <span className="text-xs font-medium">
                        {new Date(cell.date + 'T00:00:00').getDate()}
                      </span>
                      {(cell.approved_count > 0 || cell.pending_count > 0) && (
                        <span className={cn('text-[10px] font-mono tabular-nums font-medium', coverageTextColor(cell.coverage_pct))}>
                          {cell.coverage_pct}%
                        </span>
                      )}
                    </div>
                    {cell.approved_count > 0 && (
                      <div className="text-[10px] text-emerald-600 dark:text-emerald-400 font-mono tabular-nums mt-0.5">
                        {cell.approved_count} approved
                      </div>
                    )}
                    {cell.pending_count > 0 && (
                      <div className="text-[10px] text-amber-600 dark:text-amber-400 font-mono tabular-nums">
                        {cell.pending_count} pending
                      </div>
                    )}
                  </button>
                </Tooltip>
              ),
            )}
          </div>

          {/* Legend */}
          <div className="flex items-center gap-4 mt-4 text-xs text-muted">
            <div className="flex items-center gap-1.5">
              <span className="w-3 h-3 rounded-sm bg-emerald-100 dark:bg-emerald-900 border border-emerald-300 dark:border-emerald-700" />
              &gt;80% present
            </div>
            <div className="flex items-center gap-1.5">
              <span className="w-3 h-3 rounded-sm bg-amber-100 dark:bg-amber-900 border border-amber-300 dark:border-amber-700" />
              60-80% present
            </div>
            <div className="flex items-center gap-1.5">
              <span className="w-3 h-3 rounded-sm bg-red-100 dark:bg-red-900 border border-red-300 dark:border-red-700" />
              &lt;60% present
            </div>
          </div>
        </div>
      )}

      {/* Detail panel for selected day */}
      {selectedDay && selectedDay.employees_on_leave.length > 0 && (
        <div className="px-5 pb-4">
          <Panel
            title={`Employees on leave — ${new Date(selectedDay.date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`}
            meta={`${selectedDay.present_count}/${selectedDay.headcount} present (${selectedDay.coverage_pct}% coverage)`}
          >
            <div className="divide-y divide-subtle">
              {selectedDay.employees_on_leave.map((emp, i) => (
                <div key={i} className="flex items-center justify-between py-2 first:pt-0 last:pb-0">
                  <span className="text-sm">{emp.employee_name}</span>
                  <div className="flex items-center gap-2">
                    <span className="text-xs text-muted">{emp.leave_type}</span>
                    <span className={cn(
                      'text-[10px] font-medium px-1.5 py-0.5 rounded',
                      emp.status === 'approved'
                        ? 'bg-emerald-100 dark:bg-emerald-900 text-emerald-700 dark:text-emerald-300'
                        : 'bg-amber-100 dark:bg-amber-900 text-amber-700 dark:text-amber-300',
                    )}>
                      {emp.status.replace('_', ' ')}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </Panel>
        </div>
      )}

      {selectedDay && selectedDay.employees_on_leave.length === 0 && (
        <div className="px-5 pb-4">
          <Panel title={`${new Date(selectedDay.date + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}`}>
            <p className="text-sm text-muted">No employees on leave this day.</p>
          </Panel>
        </div>
      )}
    </div>
  );
}
