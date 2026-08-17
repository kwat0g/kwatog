import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { LuPlus, LuPencil, LuTrash2, LuArchiveRestore } from '@/lib/icons';
import { positionsApi } from '@/api/hr/positions';
import { departmentsApi } from '@/api/hr/departments';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import {
 DataTable, NumCell, StackedCell, type Column,
} from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatInt } from '@/lib/formatNumber';
import type { ApiValidationError, ListParams } from '@/types';
import { onFormInvalid } from '@/lib/formErrors';
import type { Position } from '@/types/hr';

import { useUrlFilters } from '@/hooks/useUrlFilters';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
const schema = z.object({
 title: z.string().trim().min(1, 'Title is required').max(100)
 .regex(/^[\p{L}0-9\s.&,()/-]+$/u, 'Letters, digits, spaces, and . & - , ( ) /'),
 department_id: z.string().min(1, 'Department is required'),
 salary_grade: z.string().max(20).regex(/^[A-Za-z0-9-]*$/, 'Letters, digits, hyphens only').optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

interface PositionFilterParams extends ListParams { department_id?: string }

export default function PositionsPage() {
 const { can } = usePermission();
 const qc = useQueryClient();

 const [filters, setFilters] = useUrlFilters<PositionFilterParams>({
  page: 1, per_page: 25, sort: 'title', direction: 'asc',
 });
 const [modalOpen, setModalOpen] = useState(false);
 const [editing, setEditing] = useState<Position | null>(null);
 const [pendingDelete, setPendingDelete] = useState<Position | null>(null);
 const [pendingRestore, setPendingRestore] = useState<Position | null>(null);
 const [selectedId, setSelectedId] = useState<string | null>(null);
 const [scope, setScope] = useState<ArchiveScope>('active');

 const params = useMemo(() => ({ ...filters, trashed: archiveToTrashed(scope) }), [filters, scope]);

 const { data: depts = [] } = useQuery({
  queryKey: ['hr', 'departments', 'tree'],
  queryFn: () => departmentsApi.tree(),
 });

 const { data, isLoading, isError, refetch } = useQuery({
  queryKey: ['hr', 'positions', params],
  queryFn: () => positionsApi.list(params),
  placeholderData: (prev) => prev,
 });

 const selected = useMemo(
 () => data?.data.find((p) => p.id === selectedId) ?? null,
 [data, selectedId],
 );

 const deleteMutation = useMutation({
  mutationFn: (id: string) => positionsApi.delete(id),
  onSuccess: () => {
  qc.invalidateQueries({ queryKey: ['hr', 'positions'] });
  toast.success('Position archived.');
  setPendingDelete(null);
  setSelectedId(null);
  },
  onError: (e: AxiosError<{ message?: string }>) => {
  toast.error(e.response?.data?.message ?? 'Failed to archive position.');
  },
 });

 const restoreMutation = useMutation({
  mutationFn: (id: string) => positionsApi.restore(id),
  onSuccess: () => {
  qc.invalidateQueries({ queryKey: ['hr', 'positions'] });
  toast.success('Position restored.');
  setPendingRestore(null);
  setScope('active');
  setSelectedId(null);
  },
  onError: (e: AxiosError<{ message?: string }>) => {
  toast.error(e.response?.data?.message ?? 'Failed to restore position.');
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
 subtitle={data ? `${formatInt(data.meta.total)} positions` : undefined}
 actions={
 can('hr.positions.manage') && (
 <Button variant="primary" size="sm" onClick={() => { setEditing(null); setModalOpen(true); }} icon={<LuPlus size={14} />}>
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
  searchPlaceholder="Search title…"
  actions={
  <ArchiveFilter
  value={scope}
  onChange={(s) => { setScope(s); setFilters((f) => ({ ...f, page: 1 })); }}
  />
  }
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
 <ListEmptyState searchTerm={filters.search as string | undefined} />
 )}

 {data && data.data.length > 0 && (
 <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
 <DataTable
 onRowClick={(row) => setSelectedId(row.id)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onSort={(sort, direction) => setFilters((f) => ({ ...f, sort, direction, page: 1 }))}
 currentSort={filters.sort}
 currentDirection={filters.direction}
 highlightedRowId={selectedId}
 />
 <Panel title="Details">
 {!selected && <p className="text-sm text-muted">Select a position to view its details.</p>}
 {selected && (
 <div className="space-y-3 text-sm">
 <DetailRow label="Title" value={selected.title} />
 <DetailRow label="Department" value={selected.department?.name ?? '—'} />
 <DetailRow label="Salary grade" value={selected.salary_grade || '—'} mono />
 <DetailRow label="Employees" value={String(selected.employees_count ?? 0)} mono />
 {can('hr.positions.manage') && (
  <ModalFooter className="justify-start">
  <Button variant="secondary" size="sm" onClick={() => { setEditing(selected); setModalOpen(true); }} icon={<LuPencil size={12} />}>
  Edit
  </Button>
  {scope === 'only' ? (
  <Button variant="secondary" size="sm" onClick={() => setPendingRestore(selected)} icon={<LuArchiveRestore size={12} />}>
  Restore
  </Button>
  ) : (
  <Button variant="danger" size="sm" onClick={() => setPendingDelete(selected)} icon={<LuTrash2 size={12} />}>
  Delete
  </Button>
  )}
  </ModalFooter>
 )}
 </div>
 )}
 </Panel>
 </div>
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
  <ConfirmDialog
  isOpen
  onClose={() => setPendingDelete(null)}
  onConfirm={() => deleteMutation.mutate(pendingDelete.id)}
  title="Archive position?"
  description={<>Archive <span className="font-medium">{pendingDelete.title}</span>? It will be hidden and can be restored later.</>}
  variant="danger"
  confirmLabel="Archive"
  pending={deleteMutation.isPending}
  />
 )}

 {pendingRestore && (
  <ConfirmDialog
  isOpen
  onClose={() => setPendingRestore(null)}
  onConfirm={() => restoreMutation.mutate(pendingRestore.id)}
  title="Restore position?"
  description={<>Restore <span className="font-medium">{pendingRestore.title}</span>? It will reappear in active lists.</>}
  confirmLabel="Restore"
  pending={restoreMutation.isPending}
  />
 )}
 </div>
 );
}

function DetailRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
 return (
 <div>
 <div className="text-xs uppercase tracking-wider text-muted font-medium mb-1">{label}</div>
 <div className={mono ? 'font-mono tabular-nums' : ''}>{value}</div>
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
 toast.error(e.response?.data?.message || 'Validation failed.');
 } else {
 toast.error('Failed to save position.');
 }
 },
 });

 return (
 <Modal isOpen onClose={onClose} title={isEdit ? 'Edit position' : 'Add position'}>
 <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())} className="space-y-3 py-2">
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
 <ModalFooter>
 <Button type="button" variant="secondary" onClick={onClose} disabled={isSubmitting || mutation.isPending}>Cancel</Button>
 <Button type="submit" variant="primary" disabled={isSubmitting || mutation.isPending} loading={mutation.isPending}>
 {mutation.isPending ? 'Saving…' : isEdit ? 'Save changes' : 'Create position'}
 </Button>
 </ModalFooter>
 </form>
 </Modal>
 );
}
