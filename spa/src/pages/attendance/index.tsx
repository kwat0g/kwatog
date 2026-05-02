import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Upload, Calendar } from 'lucide-react';
import { attendancesApi, type AttendanceListParams } from '@/api/attendance/attendances';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { Attendance } from '@/types/attendance';

export default function AttendancePage() {
  const { can } = usePermission();
  const navigate = useNavigate();
  const [filters, setFilters] = useState<AttendanceListParams>({
    page: 1, per_page: 25, sort: 'date', direction: 'desc',
  });

  const { data: depts = [] } = useQuery({
    queryKey: ['hr', 'departments', 'tree'],
    queryFn: () => departmentsApi.tree(),
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['attendance', 'attendances', filters],
    queryFn: () => attendancesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const fmtTime = (iso: string | null) => iso ? new Date(iso).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: false }) : '—';
  const minToHm = (m: number) => m === 0 ? '—' : `${Math.floor(m / 60)}h ${m % 60}m`;

  const columns: Column<Attendance>[] = [
    {
      key: 'date',
      header: 'Date',
      sortable: true,
      cell: (r) => <NumCell>{formatDate(r.date)}</NumCell>,
    },
    {
      key: 'employee',
      header: 'Employee',
      cell: (r) => (
        <StackedCell
          primary={r.employee?.full_name ?? '—'}
          secondary={<span className="font-mono">{r.employee?.employee_no}</span>}
        />
      ),
    },
    { key: 'shift', header: 'Shift', cell: (r) => r.shift?.name ?? '—' },
    { key: 'time_in', header: 'In', align: 'left', cell: (r) => <NumCell>{fmtTime(r.time_in)}</NumCell> },
    { key: 'time_out', header: 'Out', align: 'left', cell: (r) => <NumCell>{fmtTime(r.time_out)}</NumCell> },
    { key: 'regular_hours', header: 'Reg', sortable: true, align: 'right', cell: (r) => <NumCell>{r.regular_hours}</NumCell> },
    { key: 'overtime_hours', header: 'OT', sortable: true, align: 'right', cell: (r) => <NumCell>{r.overtime_hours}</NumCell> },
    { key: 'night_diff_hours', header: 'ND', align: 'right', cell: (r) => <NumCell>{r.night_diff_hours}</NumCell> },
    { key: 'tardiness_minutes', header: 'Tardy', align: 'right', cell: (r) => <NumCell className="text-warning-fg">{minToHm(r.tardiness_minutes)}</NumCell> },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status.replace('_', ' ')}</Chip>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'department_id',
      label: 'Department',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...depts.map((d) => ({ value: d.id, label: d.name }))],
    },
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'present', label: 'Present' },
        { value: 'absent', label: 'Absent' },
        { value: 'late', label: 'Late' },
        { value: 'halfday', label: 'Halfday' },
        { value: 'on_leave', label: 'On leave' },
        { value: 'holiday', label: 'Holiday' },
        { value: 'rest_day', label: 'Rest day' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Daily Time Records"
        subtitle={data ? `${data.meta.total.toLocaleString()} records` : undefined}
        actions={
          <>
            <Button variant="secondary" size="sm" icon={<Calendar size={14} />} onClick={() => navigate('/attendance/overtime')}>
              Overtime
            </Button>
            {can('attendance.import') && (
              <Button variant="primary" size="sm" icon={<Upload size={14} />} onClick={() => navigate('/attendance/import')}>
                Import DTR
              </Button>
            )}
          </>
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by employee no or name…"
      />

      <div className="px-5 py-2 flex gap-3 text-sm border-b border-default">
        <label className="flex items-center gap-2">
          <span className="text-muted text-xs">From</span>
          <input
            type="date"
            value={(filters.from as string) ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value, page: 1 }))}
            className="h-7 px-2 rounded-md border border-default bg-canvas text-xs font-mono focus:ring-2 focus:ring-accent"
          />
        </label>
        <label className="flex items-center gap-2">
          <span className="text-muted text-xs">To</span>
          <input
            type="date"
            value={(filters.to as string) ?? ''}
            onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value, page: 1 }))}
            className="h-7 px-2 rounded-md border border-default bg-canvas text-xs font-mono focus:ring-2 focus:ring-accent"
          />
        </label>
      </div>

      {isLoading && !data && <SkeletonTable columns={10} rows={10} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load attendance" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No attendance found"
          description={filters.search ? 'Try a different search.' : 'Import a biometric CSV to get started.'}
          action={can('attendance.import') ? <Button variant="primary" onClick={() => navigate('/attendance/import')}>Import DTR</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <DataTable
          columns={columns}
          data={data.data}
          meta={data.meta}
          onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
          onSort={(sort, direction) => setFilters((f) => ({ ...f, sort, direction, page: 1 }))}
          currentSort={filters.sort}
          currentDirection={filters.direction}
        />
      )}
    </div>
  );
}
