import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { ChevronLeft, ChevronRight, Plus, Pencil, Trash2 } from 'lucide-react';
import { addMonths, format, getDaysInMonth, startOfMonth, parseISO, isSameMonth, isSameDay, getDay } from 'date-fns';
import { holidaysApi } from '@/api/attendance/holidays';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { ApiValidationError } from '@/types';
import type { Holiday } from '@/types/attendance';
import { cn } from '@/lib/cn';

const schema = z.object({
  name: z.string().min(1).max(100),
  date: z.string().min(1, 'Required'),
  type: z.enum(['regular', 'special_non_working']),
  is_recurring: z.boolean(),
});
type FormValues = z.infer<typeof schema>;

export default function HolidaysPage() {
  const { can } = usePermission();
  const qc = useQueryClient();
  const [view, setView] = useState<'list' | 'calendar'>('list');
  const [year, setYear] = useState<number>(new Date().getFullYear());
  const [editing, setEditing] = useState<Holiday | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [pendingDelete, setPendingDelete] = useState<Holiday | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['attendance', 'holidays', year],
    queryFn: () => holidaysApi.list({ year, per_page: 200 }),
    placeholderData: (prev) => prev,
  });

  const holidays = data?.data ?? [];

  const deleteMutation = useMutation({
    mutationFn: (id: string) => holidaysApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['attendance', 'holidays'] });
      toast.success('Holiday deleted.');
      setPendingDelete(null);
    },
    onError: () => toast.error('Failed to delete holiday.'),
  });

  return (
    <div>
      <PageHeader
        title="Holidays"
        subtitle={`${holidays.length} for ${year}`}
        actions={
          <>
            <Button variant="ghost" size="sm" onClick={() => setYear((y) => y - 1)} icon={<ChevronLeft size={12} />} aria-label="Previous year" />
            <span className="font-mono tabular-nums text-sm px-1.5">{year}</span>
            <Button variant="ghost" size="sm" onClick={() => setYear((y) => y + 1)} icon={<ChevronRight size={12} />} aria-label="Next year" />
            <Button variant="secondary" size="sm" onClick={() => setView(view === 'list' ? 'calendar' : 'list')}>
              {view === 'list' ? 'Calendar view' : 'List view'}
            </Button>
            {can('attendance.holidays.manage') && (
              <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => { setEditing(null); setModalOpen(true); }}>
                Add holiday
              </Button>
            )}
          </>
        }
      />

      {isLoading && !data && <SkeletonTable columns={4} rows={8} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load holidays" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && holidays.length === 0 && (
        <EmptyState
          icon="inbox"
          title={`No holidays for ${year}`}
          action={can('attendance.holidays.manage') ? <Button variant="primary" onClick={() => { setEditing(null); setModalOpen(true); }}>Add holiday</Button> : undefined}
        />
      )}

      {data && holidays.length > 0 && view === 'list' && (
        <ListView
          holidays={holidays}
          canManage={can('attendance.holidays.manage')}
          onEdit={(h) => { setEditing(h); setModalOpen(true); }}
          onDelete={(h) => setPendingDelete(h)}
        />
      )}

      {data && holidays.length > 0 && view === 'calendar' && (
        <CalendarView
          holidays={holidays}
          year={year}
        />
      )}

      {modalOpen && (
        <HolidayFormModal
          editing={editing}
          onClose={() => { setModalOpen(false); setEditing(null); }}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['attendance', 'holidays'] });
            setModalOpen(false); setEditing(null);
          }}
        />
      )}
      {pendingDelete && (
        <Modal isOpen onClose={() => setPendingDelete(null)} size="sm" title="Delete holiday">
          <p className="text-sm py-2">Delete <span className="font-medium">{pendingDelete.name}</span> on {formatDate(pendingDelete.date)}?</p>
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

function ListView({
  holidays, canManage, onEdit, onDelete,
}: {
  holidays: Holiday[];
  canManage: boolean;
  onEdit: (h: Holiday) => void;
  onDelete: (h: Holiday) => void;
}) {
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const selected = useMemo(() => holidays.find((h) => h.id === selectedId) ?? null, [holidays, selectedId]);

  const columns: Column<Holiday>[] = [
    { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'name', header: 'Name', cell: (r) => <span className="font-medium">{r.name}</span> },
    {
      key: 'type',
      header: 'Type',
      cell: (r) => (
        <Chip variant={r.type === 'regular' ? 'warning' : 'info'}>
          {r.type === 'regular' ? 'Regular' : 'Special non-working'}
        </Chip>
      ),
    },
    { key: 'is_recurring', header: 'Recurring', cell: (r) => r.is_recurring ? <Chip variant="neutral">Yes</Chip> : <span className="text-text-subtle">—</span> },
  ];

  return (
    <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
      <DataTable
        columns={columns}
        data={holidays}
        onRowClick={(row) => setSelectedId(row.id)}
        highlightedRowId={selectedId}
      />
      <Panel title="Details">
        {!selected && <p className="text-sm text-muted">Select a holiday to view its details.</p>}
        {selected && (
          <div className="space-y-3 text-sm">
            <div>
              <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Name</div>
              <div className="font-medium">{selected.name}</div>
            </div>
            <div>
              <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Date</div>
              <div className="font-mono">{formatDate(selected.date)}</div>
            </div>
            <div>
              <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Type</div>
              <Chip variant={selected.type === 'regular' ? 'warning' : 'info'}>
                {selected.type === 'regular' ? 'Regular' : 'Special non-working'}
              </Chip>
            </div>
            <div>
              <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Recurring</div>
              <div>{selected.is_recurring ? 'Annually' : 'One-off'}</div>
            </div>
            {canManage && (
              <div className="flex gap-2 pt-3 border-t border-default">
                <Button variant="secondary" size="sm" onClick={() => onEdit(selected)} icon={<Pencil size={12} />}>Edit</Button>
                <Button variant="danger" size="sm" onClick={() => onDelete(selected)} icon={<Trash2 size={12} />}>Delete</Button>
              </div>
            )}
          </div>
        )}
      </Panel>
    </div>
  );
}

function CalendarView({ holidays, year }: { holidays: Holiday[]; year: number }) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 px-5 py-4">
      {Array.from({ length: 12 }).map((_, i) => (
        <MonthGrid key={i} year={year} month={i} holidays={holidays} />
      ))}
    </div>
  );
}

function MonthGrid({ year, month, holidays }: { year: number; month: number; holidays: Holiday[] }) {
  const ref = new Date(year, month, 1);
  const days = getDaysInMonth(ref);
  const firstDow = getDay(startOfMonth(ref));
  const cells: (Date | null)[] = [];
  for (let i = 0; i < firstDow; i++) cells.push(null);
  for (let d = 1; d <= days; d++) cells.push(new Date(year, month, d));

  const byDay = useMemo(() => {
    const map = new Map<string, Holiday[]>();
    holidays.forEach((h) => {
      const d = parseISO(h.date);
      if (isSameMonth(d, ref)) {
        const key = format(d, 'yyyy-MM-dd');
        const list = map.get(key) ?? [];
        list.push(h);
        map.set(key, list);
      }
    });
    return map;
  }, [holidays, ref]);

  return (
    <Panel title={format(ref, 'MMMM yyyy')} noPadding>
      <div className="p-2">
        <div className="grid grid-cols-7 text-2xs uppercase tracking-wider text-muted text-center mb-1">
          {['S','M','T','W','T','F','S'].map((d, i) => <div key={i} className="h-5 leading-5">{d}</div>)}
        </div>
        <div className="grid grid-cols-7 gap-px">
          {cells.map((d, i) => {
            const key = d ? format(d, 'yyyy-MM-dd') : `empty-${i}`;
            const matches = d ? byDay.get(key) ?? [] : [];
            return (
              <div
                key={key}
                className={cn(
                  'aspect-square flex flex-col items-center justify-center rounded-sm text-2xs',
                  d ? 'bg-canvas' : 'bg-transparent',
                  matches.length > 0 && (matches[0].type === 'regular' ? 'bg-warning-bg text-warning-fg font-medium' : 'bg-info-bg text-info-fg font-medium'),
                )}
                title={matches.map((h) => h.name).join(', ')}
              >
                {d && <span>{format(d, 'd')}</span>}
                {matches.length > 0 && (
                  <span className="w-1 h-1 rounded-full bg-current opacity-70 mt-0.5" />
                )}
              </div>
            );
          })}
        </div>
      </div>
    </Panel>
  );
}

function HolidayFormModal({
  editing, onClose, onSaved,
}: { editing: Holiday | null; onClose: () => void; onSaved: () => void }) {
  const isEdit = !!editing;
  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: editing?.name ?? '',
      date: editing?.date ?? '',
      type: (editing?.type as FormValues['type']) ?? 'regular',
      is_recurring: editing?.is_recurring ?? false,
    },
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => isEdit ? holidaysApi.update(editing!.id, d) : holidaysApi.create(d),
    onSuccess: () => { toast.success(isEdit ? 'Holiday updated.' : 'Holiday created.'); onSaved(); },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([f, msgs]) =>
          setError(f as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
        toast.error('Please fix the errors below.');
      } else toast.error('Failed to save holiday.');
    },
  });

  return (
    <Modal isOpen onClose={onClose} title={isEdit ? 'Edit holiday' : 'Add holiday'}>
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-3 py-2">
        <Input label="Name" required {...register('name')} error={errors.name?.message} />
        <Input label="Date" type="date" required {...register('date')} error={errors.date?.message} />
        <Select label="Type" required {...register('type')} error={errors.type?.message}>
          <option value="regular">Regular holiday</option>
          <option value="special_non_working">Special non-working</option>
        </Select>
        <div className="pt-1">
          <Switch label="Recurs annually" {...register('is_recurring')} />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting || mutation.isPending}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create holiday'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
