import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Plus, Pencil, Trash2, Users } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { shiftsApi } from '@/api/attendance/shifts';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError, ListParams } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';
import type { Shift } from '@/types/attendance';

const schema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(50)
    .regex(/^[A-Za-z0-9\s\-_().]+$/, 'Letters, digits, spaces, and -_().'),
  start_time: z.string().regex(/^\d{2}:\d{2}$/, 'Use HH:MM (24-hour)'),
  end_time: z.string().regex(/^\d{2}:\d{2}$/, 'Use HH:MM (24-hour)'),
  break_minutes: z.coerce.number().int('Whole minutes only').min(0).max(240, 'Max 240 minutes'),
  is_night_shift: z.boolean(),
  is_extended: z.boolean(),
  auto_ot_hours: z.string().optional().or(z.literal('')),
  is_active: z.boolean(),
}).refine((d) => d.start_time !== d.end_time, { message: 'End time cannot equal start time', path: ['end_time'] });
type FormValues = z.infer<typeof schema>;

export default function ShiftsPage() {
  const { can } = usePermission();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [filters, setFilters] = useState<ListParams>({ page: 1, per_page: 25 });
  const [editing, setEditing] = useState<Shift | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Shift | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['attendance', 'shifts', filters],
    queryFn: () => shiftsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const selected = useMemo(
    () => data?.data.find((s) => s.id === selectedId) ?? null,
    [data, selectedId],
  );

  const deleteMutation = useMutation({
    mutationFn: (id: string) => shiftsApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['attendance', 'shifts'] });
      toast.success('Shift deleted.');
      setPendingDelete(null);
      setSelectedId(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to delete shift.');
    },
  });

  const columns: Column<Shift>[] = [
    { key: 'name', header: 'Name', cell: (r) => <span className="font-medium">{r.name}</span> },
    { key: 'start_time', header: 'Start', align: 'left', cell: (r) => <NumCell>{r.start_time}</NumCell> },
    { key: 'end_time', header: 'End', align: 'left', cell: (r) => <NumCell>{r.end_time}</NumCell> },
    { key: 'break_minutes', header: 'Break (m)', align: 'right', cell: (r) => <NumCell>{r.break_minutes}</NumCell> },
    { key: 'is_night_shift', header: 'Night', cell: (r) => r.is_night_shift ? <Chip variant="info">Night</Chip> : <span className="text-text-subtle">—</span> },
    { key: 'is_extended', header: 'Extended', cell: (r) => r.is_extended ? <Chip variant="warning">Auto-OT {r.auto_ot_hours}h</Chip> : <span className="text-text-subtle">—</span> },
    { key: 'is_active', header: 'Status', cell: (r) => <Chip variant={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Inactive'}</Chip> },
  ];

  return (
    <div>
      <PageHeader
        title="Shifts"
        subtitle={data ? `${data.meta.total} shifts` : undefined}
        actions={
          <>
            {can('attendance.shifts.manage') && (
              <>
                <Button variant="secondary" size="sm" icon={<Users size={14} />} onClick={() => navigate('/attendance/shifts/assign')}>
                  Bulk assign
                </Button>
                <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => { setEditing(null); setModalOpen(true); }}>
                  Add shift
                </Button>
              </>
            )}
          </>
        }
      />

      <FilterBar
        filters={[]}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search by name…"
      />

      {isLoading && !data && <SkeletonTable columns={7} rows={5} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load shifts" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No shifts found"
          description="Add your first shift to start scheduling."
          action={can('attendance.shifts.manage') ? <Button variant="primary" onClick={() => { setEditing(null); setModalOpen(true); }}>Add shift</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onRowClick={(row) => setSelectedId(row.id)}
            highlightedRowId={selectedId}
          />
          <Panel title="Details">
            {!selected && <p className="text-sm text-muted">Select a shift to view its details.</p>}
            {selected && (
              <div className="space-y-3 text-sm">
                <div>
                  <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Name</div>
                  <div className="font-medium">{selected.name}</div>
                </div>
                <div className="flex gap-4">
                  <div>
                    <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Start</div>
                    <div className="font-mono tabular-nums">{selected.start_time}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">End</div>
                    <div className="font-mono tabular-nums">{selected.end_time}</div>
                  </div>
                  <div>
                    <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Break</div>
                    <div className="font-mono tabular-nums">{selected.break_minutes} min</div>
                  </div>
                </div>
                <div className="flex gap-2 flex-wrap">
                  {selected.is_night_shift && <Chip variant="info">Night shift</Chip>}
                  {selected.is_extended && <Chip variant="warning">Auto-OT {selected.auto_ot_hours}h</Chip>}
                  <Chip variant={selected.is_active ? 'success' : 'neutral'}>
                    {selected.is_active ? 'Active' : 'Inactive'}
                  </Chip>
                </div>
                {can('attendance.shifts.manage') && (
                  <div className="flex gap-2 pt-3 border-t border-default">
                    <Button variant="secondary" size="sm" onClick={() => { setEditing(selected); setModalOpen(true); }} icon={<Pencil size={12} />}>Edit</Button>
                    <Button variant="danger" size="sm" onClick={() => setPendingDelete(selected)} icon={<Trash2 size={12} />}>Delete</Button>
                  </div>
                )}
              </div>
            )}
          </Panel>
        </div>
      )}

      {modalOpen && (
        <ShiftFormModal
          editing={editing}
          onClose={() => { setModalOpen(false); setEditing(null); }}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['attendance', 'shifts'] });
            setModalOpen(false); setEditing(null);
          }}
        />
      )}
      {pendingDelete && (
        <Modal isOpen onClose={() => setPendingDelete(null)} size="sm" title="Delete shift">
          <p className="text-sm py-2">Delete <span className="font-medium">{pendingDelete.name}</span>?</p>
          <div className="flex justify-end gap-2 pt-3 border-t border-default">
            <Button variant="secondary" onClick={() => setPendingDelete(null)} disabled={deleteMutation.isPending}>Cancel</Button>
            <Button variant="danger" onClick={() => deleteMutation.mutate(pendingDelete.id)} disabled={deleteMutation.isPending} loading={deleteMutation.isPending}>
              Delete
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

function ShiftFormModal({ editing, onClose, onSaved }: { editing: Shift | null; onClose: () => void; onSaved: () => void }) {
  const isEdit = !!editing;
  const {
    register, handleSubmit, watch, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: editing?.name ?? '',
      start_time: editing?.start_time ?? '08:00',
      end_time: editing?.end_time ?? '17:00',
      break_minutes: editing?.break_minutes ?? 60,
      is_night_shift: editing?.is_night_shift ?? false,
      is_extended: editing?.is_extended ?? false,
      auto_ot_hours: editing?.auto_ot_hours ?? '',
      is_active: editing?.is_active ?? true,
    },
  });

  const isExtended = watch('is_extended');

  const mutation = useMutation({
    mutationFn: (d: FormValues) => {
      const payload = {
        ...d,
        auto_ot_hours: d.auto_ot_hours ? Number(d.auto_ot_hours) : null,
      };
      return isEdit ? shiftsApi.update(editing!.id, payload) : shiftsApi.create(payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Shift updated.' : 'Shift created.');
      onSaved();
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
        toast.error('Please fix the errors below.');
      } else {
        toast.error('Failed to save shift.');
      }
    },
  });

  return (
    <Modal isOpen onClose={onClose} title={isEdit ? 'Edit shift' : 'Add shift'}>
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="space-y-3 py-2">
        <Input label="Name" required {...register('name')} error={errors.name?.message} />
        <div className="grid grid-cols-3 gap-3">
          <Input label="Start" type="time" {...register('start_time')} error={errors.start_time?.message} className="font-mono" />
          <Input label="End" type="time" {...register('end_time')} error={errors.end_time?.message} className="font-mono" />
          <Input label="Break (min)" type="number" {...register('break_minutes')} error={errors.break_minutes?.message} className="font-mono" />
        </div>
        <div className="flex flex-col gap-2 pt-1">
          <Switch label="Night shift (22:00-06:00 hours earn 10% premium)" {...register('is_night_shift')} />
          <Switch label="Extended shift (auto-OT, no approval needed)" {...register('is_extended')} />
        </div>
        {isExtended && (
          <Input
            label="Auto-OT hours"
            type="number"
            step="0.5"
            {...register('auto_ot_hours')}
            error={errors.auto_ot_hours?.message}
            className="font-mono"
            placeholder="4.0"
          />
        )}
        <div className="pt-1">
          <Switch label="Active" {...register('is_active')} />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting || mutation.isPending}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create shift'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
