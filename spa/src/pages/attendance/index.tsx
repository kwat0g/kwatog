import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Upload, Clock, Sun } from 'lucide-react';
import { attendancesApi, type AttendanceListParams } from '@/api/attendance/attendances';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { formatTime } from '@/lib/formatDate';
import { formatInt } from '@/lib/formatNumber';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { Attendance } from '@/types/attendance';

const today = new Date().toISOString().split('T')[0];
const DEFAULT_FILTERS: AttendanceListParams = {
  page: 1, per_page: 25, sort: 'date', direction: 'desc',
  from: today, to: today,
};

export default function AttendancePage() {
 const { can } = usePermission();
 const canViewDepartments = can('hr.departments.view');
 const navigate = useNavigate();
 // Bound to the URL so dashboard drill-downs and shared date links arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<AttendanceListParams>(DEFAULT_FILTERS);

 const { data: depts = [] } = useQuery({
 queryKey: ['hr', 'departments', 'tree'],
 queryFn: () => departmentsApi.tree(),
 enabled: canViewDepartments,
 });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['attendance', 'attendances', filters],
 queryFn: () => attendancesApi.list(filters),
 placeholderData: (prev) => prev,
 });
 const { data: attendanceOptions } = useQuery({
 queryKey: ['attendance', 'attendances', 'options'],
 queryFn: attendancesApi.options,
 staleTime: 5 * 60 * 1000,
 });
 const statusLabels = new Map((attendanceOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const fmtTime = (iso: string | null) => iso ? formatTime(iso) : '—';
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
 cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip>,
 },
 ];

 const filterConfig: FilterConfig[] = [
 ...(canViewDepartments ? [{
 key: 'department_id',
 label: 'Department',
 type: 'select',
 options: [{ value: '', label: 'All' }, ...depts.map((d) => ({ value: d.id, label: d.name }))],
 } as FilterConfig] : []),
 {
 key: 'status',
 label: 'Status',
 type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(attendanceOptions?.statuses ?? []),
 ],
 },
 ];

 return (
 <div>
 <PageHeader
 title="Daily Time Records"
 subtitle={data ? `${formatInt(data.meta.total)} records` : undefined}
 actions={
 <>
 {(can('attendance.edit') || can('attendance.shifts.manage')) && (
 <Button variant="secondary" size="sm" icon={<Clock size={14} />} onClick={() => navigate('/hr/attendance/shifts')}>
 Shifts
 </Button>
 )}
 {(can('attendance.edit') || can('attendance.holidays.manage')) && (
 <Button variant="secondary" size="sm" icon={<Sun size={14} />} onClick={() => navigate('/hr/attendance/holidays')}>
 Holidays
 </Button>
 )}
 {can('attendance.import') && (
 <Button variant="primary" size="sm" icon={<Upload size={14} />} onClick={() => navigate('/hr/attendance/import')}>
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
 searchPlaceholder="Search employee no or name…"
 dateRange={{ fromKey: 'from', toKey: 'to', label: 'Date' }}
 />

 {isLoading && !data && <SkeletonTable columns={10} rows={10} />}
 {isError && (
 <EmptyState icon="alert-circle" title="Failed to load attendance" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 )}
 {data && data.data.length === 0 && (
 <EmptyState
 icon="inbox"
 title="No attendance found"
 description={filters.search ? 'Try a different search.' : 'Import a biometric CSV to get started.'}
 action={can('attendance.import') ? <Button variant="primary" onClick={() => navigate('/hr/attendance/import')}>Import DTR</Button> : undefined}
 />
 )}
 {data && data.data.length > 0 && (
  <div className="px-5 py-4"><DataTable
  tableKey="attendance-records"
  columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onSort={(sort, direction) => setFilters((f) => ({ ...f, sort, direction, page: 1 }))}
 currentSort={filters.sort}
 currentDirection={filters.direction}
 /></div>
 )}
 </div>
 );
}
