import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { LuUpload, LuClock, LuSun, LuPlus, LuArchiveRestore, LuTrash2, LuPencil } from '@/lib/icons';
import { attendancesApi, type AttendanceListParams, type CreateAttendanceData } from '@/api/attendance/attendances';
import { departmentsApi } from '@/api/hr/departments';
import { employeesApi } from '@/api/hr/employees';
import { shiftsApi } from '@/api/attendance/shifts';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { formatTime } from '@/lib/formatDate';
import { formatInt } from '@/lib/formatNumber';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { showUndoToast } from '@/lib/undoToast';
import type { Attendance, Shift } from '@/types/attendance';
import type { Employee } from '@/types/hr';

const today = new Date().toISOString().split('T')[0];
const DEFAULT_FILTERS: AttendanceListParams = {
  page: 1, per_page: 25, sort: 'date', direction: 'desc',
  from: today, to: today,
};

const attendanceFormSchema = z.object({
 employee_id: z.string().min(1, 'Employee is required.'),
 date: z.string().min(1, 'Date is required.'),
 shift_id: z.string(),
 time_in: z.string(),
 time_out: z.string(),
 is_rest_day: z.boolean(),
 remarks: z.string().max(1000, 'Remarks may not exceed 1,000 characters.'),
});

type AttendanceFormValues = z.infer<typeof attendanceFormSchema>;

function isoToTime(value: string | null): string {
 if (!value) return '';
 const match = value.match(/T(\d{2}:\d{2})/);
 return match?.[1] ?? value.slice(0, 5);
}

function AttendanceCorrectionModal({
 isOpen,
 onClose,
 attendance,
 employees,
 shifts,
 onSaved,
}: {
 isOpen: boolean;
 onClose: () => void;
 attendance: Attendance | null;
 employees: Employee[];
 shifts: Shift[];
 onSaved: () => void;
}) {
 const isEdit = attendance !== null;
 const form = useForm<AttendanceFormValues>({
 resolver: zodResolver(attendanceFormSchema),
 defaultValues: {
 employee_id: '', date: today, shift_id: '', time_in: '', time_out: '', is_rest_day: false, remarks: '',
 },
 });
 const { register, handleSubmit, reset, setError, formState: { errors } } = form;

 useEffect(() => {
  if (!isOpen) return;
  reset(attendance ? {
   employee_id: attendance.employee?.id ?? '',
   date: attendance.date,
   shift_id: attendance.shift?.id ?? '',
   time_in: isoToTime(attendance.time_in),
   time_out: isoToTime(attendance.time_out),
   is_rest_day: attendance.is_rest_day,
   remarks: attendance.remarks ?? '',
  } : {
   employee_id: '', date: today, shift_id: '', time_in: '', time_out: '', is_rest_day: false, remarks: '',
  });
 }, [attendance, isOpen, reset]);

 const mutation = useMutation({
  mutationFn: (values: AttendanceFormValues) => {
   const payload: CreateAttendanceData = {
    employee_id: values.employee_id,
    date: values.date,
    shift_id: values.shift_id || undefined,
    time_in: values.time_in || undefined,
    time_out: values.time_out || undefined,
    is_rest_day: values.is_rest_day,
    remarks: values.remarks || undefined,
   };
   if (attendance) {
    return attendancesApi.update(attendance.id, {
     shift_id: payload.shift_id,
     time_in: payload.time_in,
     time_out: payload.time_out,
     is_rest_day: payload.is_rest_day,
     remarks: payload.remarks,
    });
   }
   return attendancesApi.create(payload);
  },
  onSuccess: () => {
   toast.success(isEdit ? 'Attendance record updated.' : 'Attendance record created.');
   onSaved();
   onClose();
  },
  onError: (error) => {
   const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
   const payload = response?.data;
   const general = payload?.errors?.error?.[0] ?? (payload?.message && !payload.errors ? payload.message : undefined);
   if (general) {
    setError('root', { type: 'server', message: general });
    toast.error(general);
    return;
   }
   applyServerValidationErrors(error, setError, 'Failed to save attendance record.');
  },
 });

 return (
  <Modal isOpen={isOpen} onClose={onClose} title={isEdit ? 'Correct attendance record' : 'Add manual attendance'} size="lg">
   <form onSubmit={handleSubmit((values) => mutation.mutate(values), onFormInvalid<AttendanceFormValues>())} className="space-y-4">
    {errors.root?.message && <div role="alert" className="rounded-md border border-danger bg-danger/10 px-3 py-2 text-sm text-danger-fg">{errors.root.message}</div>}
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
     <Select label="Employee" required disabled={isEdit} {...register('employee_id')} error={errors.employee_id?.message}>
      <option value="">— Select employee —</option>
      {employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.full_name} · {employee.employee_no}</option>)}
     </Select>
     <Input label="Date" type="date" required disabled={isEdit} {...register('date')} error={errors.date?.message} />
     <Select label="Shift" {...register('shift_id')} error={errors.shift_id?.message}>
      <option value="">— No shift —</option>
      {shifts.map((shift) => <option key={shift.id} value={shift.id}>{shift.name}</option>)}
     </Select>
     <label className="flex items-center gap-2 self-end pb-1 text-sm text-primary">
      <input type="checkbox" className="h-4 w-4 accent-accent" {...register('is_rest_day')} />
      Rest day
     </label>
     <Input label="Time in" type="time" {...register('time_in')} error={errors.time_in?.message} />
     <Input label="Time out" type="time" {...register('time_out')} error={errors.time_out?.message} />
    </div>
    <Textarea label="Remarks" rows={3} {...register('remarks')} error={errors.remarks?.message} placeholder="Reason or source of the correction" />
    <ModalFooter>
     <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
     <Button type="submit" variant="primary" loading={mutation.isPending} disabled={mutation.isPending}>
      {isEdit ? 'Save correction' : 'Create record'}
     </Button>
    </ModalFooter>
   </form>
  </Modal>
 );
}

export default function AttendancePage() {
 const { can } = usePermission();
 const canViewDepartments = can('hr.departments.view');
 const canEdit = can('attendance.edit');
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const [correctionOpen, setCorrectionOpen] = useState(false);
 const [editingAttendance, setEditingAttendance] = useState<Attendance | null>(null);
 const [archiveScope, setArchiveScope] = useState<ArchiveScope>('active');
 // Bound to the URL so dashboard drill-downs and shared date links arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<AttendanceListParams>(DEFAULT_FILTERS);

 const { data: depts = [] } = useQuery({
 queryKey: ['hr', 'departments', 'tree'],
 queryFn: () => departmentsApi.tree(),
 enabled: canViewDepartments,
 });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['attendance', 'attendances', filters, archiveScope],
 queryFn: () => attendancesApi.list({ ...filters, trashed: archiveToTrashed(archiveScope) }),
 placeholderData: (prev) => prev,
 });
 const { data: attendanceOptions } = useQuery({
 queryKey: ['attendance', 'attendances', 'options'],
 queryFn: attendancesApi.options,
 staleTime: 5 * 60 * 1000,
 });
 const { data: employeeData } = useQuery({
  queryKey: ['hr', 'employees', 'attendance-options'],
  queryFn: () => employeesApi.list({ status: 'active', per_page: 100, sort: 'last_name', direction: 'asc' }),
  enabled: canEdit && correctionOpen,
 });
 const { data: shiftData } = useQuery({
  queryKey: ['attendance', 'shifts', 'attendance-options'],
  queryFn: () => shiftsApi.list({ is_active: true, per_page: 100, sort: 'name', direction: 'asc' }),
  enabled: canEdit && correctionOpen,
 });
 const restoreMutation = useMutation({
  mutationFn: (id: string) => attendancesApi.restore(id),
  onSuccess: () => {
   queryClient.invalidateQueries({ queryKey: ['attendance', 'attendances'] });
   toast.success('Attendance record restored.');
   setArchiveScope('active');
  },
  onError: () => toast.error('Failed to restore attendance record.'),
 });
 const archiveMutation = useMutation({
  mutationFn: (id: string) => attendancesApi.delete(id),
  onSuccess: (_data, archivedId: string) => {
   queryClient.invalidateQueries({ queryKey: ['attendance', 'attendances'] });
   showUndoToast({
    message: 'Attendance record archived.',
    onUndo: () => restoreMutation.mutate(archivedId),
   });
  },
  onError: () => toast.error('Failed to archive attendance record.'),
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

 const openCreate = () => {
  setEditingAttendance(null);
  setCorrectionOpen(true);
 };

 const openEdit = (attendance: Attendance) => {
  if (!canEdit || attendance.deleted_at) return;
  setEditingAttendance(attendance);
  setCorrectionOpen(true);
 };

 const closeCorrection = () => {
  setCorrectionOpen(false);
  setEditingAttendance(null);
 };

 return (
 <div>
 <PageHeader
 title="Daily Time Records"
 subtitle={data ? `${formatInt(data.meta.total)} records` : undefined}
 actions={
 <>
 {canEdit && (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={openCreate}>
 Manual DTR
 </Button>
 )}
 {(can('attendance.edit') || can('attendance.shifts.manage')) && (
 <Button variant="secondary" size="sm" icon={<LuClock size={14} />} onClick={() => navigate('/hr/attendance/shifts')}>
 Shifts
 </Button>
 )}
 {(can('attendance.edit') || can('attendance.holidays.manage')) && (
 <Button variant="secondary" size="sm" icon={<LuSun size={14} />} onClick={() => navigate('/hr/attendance/holidays')}>
 Holidays
 </Button>
 )}
 {can('attendance.import') && (
 <Button variant="primary" size="sm" icon={<LuUpload size={14} />} onClick={() => navigate('/hr/attendance/import')}>
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
 actions={<ArchiveFilter value={archiveScope} onChange={setArchiveScope} />}
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
 action={canEdit ? <Button variant="primary" onClick={openCreate}>Add manual DTR</Button> : can('attendance.import') ? <Button variant="primary" onClick={() => navigate('/hr/attendance/import')}>Import DTR</Button> : undefined}
 />
 )}
 {data && data.data.length > 0 && (
  <div className="px-5 py-4"><DataTable
  tableKey="attendance-records"
  columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 onSort={(sort, direction) => setFilters((f) => ({ ...f, sort, direction, page: 1 }))}
 currentSort={filters.sort}
 currentDirection={filters.direction}
 onRowClick={canEdit ? openEdit : undefined}
 rowContextMenu={canEdit ? (row) => [
  ...(row.deleted_at ? [] : [{ label: 'Correct record', icon: <LuPencil size={13} />, onClick: () => openEdit(row) }]),
  row.deleted_at
   ? { label: 'Restore record', icon: <LuArchiveRestore size={13} />, onClick: () => restoreMutation.mutate(row.id) }
   : { label: 'Archive record', icon: <LuTrash2 size={13} />, variant: 'danger' as const, onClick: () => archiveMutation.mutate(row.id) },
 ] : undefined}
 /></div>
 )}
 <AttendanceCorrectionModal
  isOpen={correctionOpen}
  onClose={closeCorrection}
  attendance={editingAttendance}
  employees={employeeData?.data ?? []}
  shifts={shiftData?.data ?? []}
  onSaved={() => queryClient.invalidateQueries({ queryKey: ['attendance', 'attendances'] })}
 />
 </div>
 );
}
