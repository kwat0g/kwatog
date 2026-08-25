/** OGAMI-016 — IATF calibration register list and record flow. */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { LuCalendarClock, LuPencil, LuPlus } from '@/lib/icons';
import { calibrationApi, type CalibrationListParams } from '@/api/quality/calibration';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { CalibrationRecord, CalibrationStatus } from '@/types/quality';

const STATUS_VARIANT: Record<CalibrationStatus, ChipVariant> = {
 active: 'success',
 due: 'warning',
 overdue: 'danger',
 retired: 'neutral',
};

export default function CalibrationListPage() {
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const { can } = usePermission();
 const [filters, setFilters] = useUrlFilters<CalibrationListParams>({ page: 1, per_page: 25 });
 const [recording, setRecording] = useState<CalibrationRecord | null>(null);
 const [recordDate, setRecordDate] = useState(() => new Date().toISOString().slice(0, 10));
 const [recordDateError, setRecordDateError] = useState<string | undefined>();

 const query = useQuery({
  queryKey: ['quality', 'calibration', filters],
  queryFn: () => calibrationApi.list(filters),
  placeholderData: (previous) => previous,
 });

 const recordMutation = useMutation({
  mutationFn: ({ id, date }: { id: string; date: string }) => calibrationApi.record(id, date),
  onSuccess: () => {
   toast.success('Calibration recorded');
   setRecording(null);
   setRecordDateError(undefined);
   void queryClient.invalidateQueries({ queryKey: ['quality', 'calibration'] });
  },
  onError: (error: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
   setRecordDateError(error.response?.data?.errors?.date?.[0]);
   toast.error(error.response?.data?.message ?? 'Could not record calibration.');
  },
 });

 const columns: Column<CalibrationRecord>[] = [
  { key: 'equipment_code', header: 'Code', cell: (r) => <span className="font-mono">{r.equipment_code}</span> },
  { key: 'name', header: 'Equipment', cell: (r) => <span>{r.name}</span> },
  { key: 'location', header: 'Location', cell: (r) => <span className="text-muted">{r.location ?? '—'}</span> },
  { key: 'last', header: 'Last calibrated', cell: (r) => <NumCell>{r.last_calibration_date ?? '—'}</NumCell> },
  { key: 'next', header: 'Next due', cell: (r) => <NumCell>{r.next_calibration_date ?? '—'}</NumCell> },
  { key: 'frequency', header: 'Frequency', align: 'right', cell: (r) => <NumCell>{r.frequency_days}d</NumCell> },
  { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_VARIANT[r.status]}>{r.status_label ?? r.status}</Chip> },
  {
   key: 'actions',
   header: '',
   align: 'right',
   togglable: false,
   cell: (r) => can('quality.calibration.manage') ? (
    <div className="flex items-center justify-end gap-1" onClick={(event) => event.stopPropagation()}>
     <Button
      size="sm"
      variant="ghost"
      icon={<LuPencil size={13} />}
      aria-label={`Edit ${r.equipment_code}`}
      onClick={() => navigate(`/quality/calibration/${r.id}/edit`)}
     />
     <Button
      size="sm"
      variant="secondary"
      icon={<LuCalendarClock size={13} />}
      onClick={() => {
       setRecording(r);
       setRecordDate(new Date().toISOString().slice(0, 10));
       setRecordDateError(undefined);
      }}
     >
      Record
     </Button>
    </div>
   ) : null,
  },
 ];

 const filterConfig: FilterConfig[] = [
  {
   key: 'status',
   label: 'Status',
   type: 'select',
   options: [
    { value: '', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'due', label: 'Due' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'retired', label: 'Retired' },
   ],
  },
 ];

 return (
  <div>
   <PageHeader
    title="Calibration register"
    subtitle={query.data ? `${query.data.meta.total} ${query.data.meta.total === 1 ? 'instrument' : 'instruments'}` : undefined}
    actions={can('quality.calibration.manage') ? (
     <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/quality/calibration/new')}>
      New instrument
     </Button>
    ) : undefined}
   />
   <FilterBar
    filters={filterConfig}
    values={filters}
    onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value, page: 1 }))}
   />
   {query.isLoading && !query.data && <SkeletonTable columns={8} rows={6} />}
   {query.isError && (
    <EmptyState
     icon="alert-circle"
     title="Could not load the calibration register"
     description="This is a loading problem, not an empty register. Try again."
     action={<Button variant="secondary" onClick={() => void query.refetch()}>Retry</Button>}
    />
   )}
   {query.data && query.data.data.length === 0 && (
    <EmptyState
     icon="clipboard-check"
     title="No calibration instruments"
     description="Register the gauges and measuring equipment used by quality control."
     action={can('quality.calibration.manage') ? <Button variant="primary" onClick={() => navigate('/quality/calibration/new')}>New instrument</Button> : undefined}
    />
   )}
   {query.data && query.data.data.length > 0 && (
    <div className="px-5 py-4">
     <DataTable
      tableKey="quality.calibration"
      onRowClick={can('quality.calibration.manage') ? (record) => navigate(`/quality/calibration/${record.id}/edit`) : undefined}
      columns={columns}
      data={query.data.data}
      meta={query.data.meta}
      onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
      onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
     />
    </div>
   )}

   <Modal isOpen={recording !== null} onClose={() => setRecording(null)} title="Record calibration" size="sm">
    <p className="text-sm text-muted mb-4">
     Record the completed calibration date for <span className="font-mono text-primary">{recording?.equipment_code}</span>.
    </p>
    <Input
     label="Calibration date"
     type="date"
     value={recordDate}
     max={new Date().toISOString().slice(0, 10)}
     onChange={(event) => {
      setRecordDate(event.target.value);
      setRecordDateError(undefined);
     }}
     error={recordDateError}
     required
    />
    <ModalFooter>
     <Button variant="secondary" onClick={() => setRecording(null)}>Cancel</Button>
     <Button
      variant="primary"
      loading={recordMutation.isPending}
      disabled={!recording || !recordDate}
      onClick={() => recording && recordMutation.mutate({ id: recording.id, date: recordDate })}
     >
      Record calibration
     </Button>
    </ModalFooter>
   </Modal>
  </div>
 );
}
