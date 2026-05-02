import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { positionsApi } from '@/api/hr/positions';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import {
  DataTable, NumCell, StackedCell, type Column,
} from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError, ListParams } from '@/types';
import type { Position } from '@/types/hr';

const schema = z.object({
  title: z.string().min(1).max(100),
  department_id: z.string().min(1, 'Department is required'),
  salary_grade: z.string().max(20).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

interface PositionFilterParams extends ListParams { department_id?: string }

export default function PositionsPage() {
  const { can } = usePermission();
  const qc = useQueryClient();

  const [filters, setFilters] = useState<PositionFilterParams>({
    page: 1, per_page: 25, sort: 'title', direction: 'asc',
  });
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Position | null>(null);
  const [pendingDelete, setPendingDelete] = useState<Position | null>(null);

  const { data: depts = [] } = useQuery({
    queryKey: ['hr', 'departments', 'tree'],
    queryFn: () => departmentsApi.tree(),
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'positions', filters],
    queryFn: () => positionsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => positionsApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['hr', 'positions'] });
      toast.success('Position deleted.');
      setPendingDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to delete position.');
    },
  });

  const columns: Column<Position>[] = [
    {
      key: 'title',
      header: 'Title',
      sortable: true,
      cell: (row) => (
        <StackedCell
          primary={row.title}
          secondary={row.salary_grade ? <span className="font-mono">{row.salary_grade}</span> : null}
        />
      ),
    },
    {
      key: 'department',
      header: 'Department',
      cell: (row) => row.department?.name ?? '—',
    },
    {
      key: 'employees_count',
      header: 'Employees',
      align: 'right',
      cell: (row) => <NumCell>{row.employees_count ?? 0}</NumCell>,
    },
    ...(can('hr.positions.manage')
      ? [{
          key: 'actions',
          header: '',
          align: 'right' as const,
          cell: (row: Position) => (
            <div className="flex items-center justify-end gap-1">
              <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); setEditing(row); setModalOpen(true); }} icon={<Pencil size={12} />} aria-label="Edit" />
              <Button variant="ghost" size="sm" onClick={(e) => { e.stopPropagation(); setPendingDelete(row); }} icon={<Trash2 size={12} />} aria-label="Delete" />
            </div>
          ),
        }]
      : []),
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'department_id',
      label: 'Department',
      type: 'select',
      options: [
        { value: '', label: 'All departments' },
        ...depts.map((d) => ({ value: d.id, label: d.name })),
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Positions"
        subtitle={data ? `${data.meta.total.toLocaleString()} positions` : undefined}
        actions={
          can('hr.positions.manage') && (
            <Button variant="primary" size="sm" onClick={() => { setEditing(null); setModalOpen(true); }} icon={<Plus size={14} />}>
              Add position
            </Button>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by title…"
      />

      {isLoading && !data && <SkeletonTable columns={4} rows={10} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load positions"
          description="Something went wrong. Please try again."
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No positions found"
          description={filters.search ? `No matches for "${filters.search}".` : 'Get started by adding a position.'}
          action={can('hr.positions.manage') ? <Button variant="primary" onClick={() => { setEditing(null); setModalOpen(true); }}>Add position</Button> : undefined}
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

      {modalOpen && (
        <PositionFormModal
          editing={editing}
          departments={depts}
          onClose={() => { setModalOpen(false); setEditing(null); }}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['hr', 'positions'] });
            setModalOpen(false);
            setEditing(null);
          }}
        />
      )}

      {pendingDelete && (
        <Modal isOpen onClose={() => setPendingDelete(null)} size="sm" title="Delete position">
          <p className="text-sm py-2">
            Delete <span className="font-medium">{pendingDelete.title}</span>?
          </p>
          <div className="flex justify-end gap-2 pt-3 border-t border-default">
            <Button variant="secondary" onClick={() => setPendingDelete(null)} disabled={deleteMutation.isPending}>Cancel</Button>
            <Button
              variant="danger"
              onClick={() => deleteMutation.mutate(pendingDelete.id)}
              disabled={deleteMutation.isPending}
              loading={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

function PositionFormModal({
  editing, departments, onClose, onSaved,
}: {
  editing: Position | null;
  departments: { id: string; name: string }[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = !!editing;
  const {
    register, handleSubmit, setError,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: editing?.title ?? '',
      department_id: editing?.department_id ?? '',
      salary_grade: editing?.salary_grade ?? '',
    },
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => isEdit
      ? positionsApi.update(editing!.id, d)
      : positionsApi.create(d),
    onSuccess: () => {
      toast.success(isEdit ? 'Position updated.' : 'Position created.');
      onSaved();
    },
    onError: (e: AxiosError<ApiValidationError>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) =>
          setError(field as keyof FormValues, { type: 'server', message: msgs[0] }),
        );
        toast.error('Please fix the errors below.');
      } else {
        toast.error('Failed to save position.');
      }
    },
  });

  return (
    <Modal isOpen onClose={onClose} title={isEdit ? 'Edit position' : 'Add position'}>
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-3 py-2">
        <Input label="Title" {...register('title')} error={errors.title?.message} required />
        <Select
          label="Department"
          {...register('department_id')}
          error={errors.department_id?.message}
          required
        >
          <option value="">— Select department —</option>
          {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </Select>
        <Input label="Salary grade" {...register('salary_grade')} error={errors.salary_grade?.message} placeholder="Optional" />
        <div className="flex justify-end gap-2 pt-3 border-t border-default">
          <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting || mutation.isPending}>Cancel</Button>
          <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create position'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
