import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { LuArchiveRestore, LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import { uomsApi, type UomOption } from '@/api/inventory/uoms';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { onFormInvalid } from '@/lib/formErrors';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError } from '@/types';

const schema = z.object({
 code: z.string().trim().min(1, 'Code is required.').max(20, 'Code must be at most 20 characters.'),
 name: z.string().trim().min(1, 'Name is required.').max(80, 'Name must be at most 80 characters.'),
});

type FormValues = z.infer<typeof schema>;

export default function UomCatalogPage() {
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('inventory.items.manage');
 const [scope, setScope] = useState<ArchiveScope>('active');
 const [search, setSearch] = useState('');
 const [formOpen, setFormOpen] = useState(false);
 const [editing, setEditing] = useState<UomOption | null>(null);
 const [pending, setPending] = useState<{ unit: UomOption; action: 'archive' | 'restore' } | null>(null);
 const trashed = archiveToTrashed(scope);

 const query = useQuery({
  queryKey: ['inventory', 'uoms', { trashed }],
  queryFn: () => trashed ? uomsApi.listArchived(trashed) : uomsApi.list(),
 });

 const rows = useMemo(() => {
  const q = search.trim().toLowerCase();
  return (query.data ?? []).filter((unit) => !q || `${unit.code} ${unit.name}`.toLowerCase().includes(q));
 }, [query.data, search]);

 const archive = useMutation({
  mutationFn: (id: string) => uomsApi.delete(id),
  onSuccess: () => {
   qc.invalidateQueries({ queryKey: ['inventory', 'uoms'] });
   toast.success('Unit archived.');
   setPending(null);
  },
  onError: (e: AxiosError<{ message?: string }>) => {
   toast.error(e.response?.data?.message ?? 'Failed to archive unit.');
  },
 });

 const restore = useMutation({
  mutationFn: (id: string) => uomsApi.restore(id),
  onSuccess: () => {
   qc.invalidateQueries({ queryKey: ['inventory', 'uoms'] });
   toast.success('Unit restored.');
   setPending(null);
  },
  onError: (e: AxiosError<{ message?: string }>) => {
   toast.error(e.response?.data?.message ?? 'Failed to restore unit.');
  },
 });

 const columns: Column<UomOption>[] = [
  { key: 'code', header: 'Code', cell: (unit) => <span className="font-mono">{unit.code}</span> },
  { key: 'name', header: 'Name', cell: (unit) => unit.name },
  {
   key: 'status',
   header: 'Status',
   cell: (unit) => unit.deleted_at ? <Chip variant="neutral">Archived</Chip> : <Chip variant="success">Active</Chip>,
  },
  {
   key: 'actions',
   header: '',
   align: 'right',
   cell: (unit) => canManage && (
    <div className="flex justify-end gap-1">
     {!unit.deleted_at && (
      <Button
       type="button"
       variant="ghost"
       size="sm"
       iconOnly
       icon={<LuPencil size={14} />}
       aria-label={`Edit ${unit.code}`}
       onClick={() => { setEditing(unit); setFormOpen(true); }}
      />
     )}
     <Button
      type="button"
      variant="ghost"
      size="sm"
      iconOnly
      icon={unit.deleted_at ? <LuArchiveRestore size={14} /> : <LuTrash2 size={14} />}
      aria-label={`${unit.deleted_at ? 'Restore' : 'Archive'} ${unit.code}`}
      className={unit.deleted_at ? 'text-muted hover:text-primary' : 'text-muted hover:text-danger-fg'}
      onClick={() => setPending({ unit, action: unit.deleted_at ? 'restore' : 'archive' })}
     />
    </div>
   ),
  },
 ];

 return (
  <div>
   <PageHeader
    title="Units of Measure"
    backTo="/inventory/items"
    backLabel="Items"
    actions={canManage && (
     <Button variant="primary" icon={<LuPlus size={15} />} onClick={() => { setEditing(null); setFormOpen(true); }}>
      New unit
     </Button>
    )}
   />
   <div className="px-5 py-4 space-y-4">
    <div className="flex flex-wrap items-center justify-between gap-3">
     <FilterBar onSearch={setSearch} searchPlaceholder="Search units..." />
     <ArchiveFilter value={scope} onChange={setScope} />
    </div>

    {query.isLoading && !query.data && <SkeletonTable rows={6} columns={4} />}
    {query.isError && (
     <EmptyState icon="alert-circle" title="Failed to load units" action={<Button variant="secondary" onClick={() => query.refetch()}>Retry</Button>} />
    )}
    {query.data && rows.length === 0 && (
     <EmptyState
      icon="inbox"
      title={search ? 'No matching units' : 'No units in this view'}
      description={!search && canManage ? 'Add a unit for inventory, purchasing, or quality records.' : undefined}
      action={!search && canManage ? <Button variant="primary" onClick={() => { setEditing(null); setFormOpen(true); }}>New unit</Button> : undefined}
     />
    )}
    {query.data && rows.length > 0 && <DataTable columns={columns} data={rows} />}
   </div>

   <Modal isOpen={formOpen} onClose={() => setFormOpen(false)} title={editing ? `Edit ${editing.code}` : 'New unit'} size="sm">
    <UomForm
     unit={editing}
     onClose={() => setFormOpen(false)}
     onSaved={() => {
      qc.invalidateQueries({ queryKey: ['inventory', 'uoms'] });
      setFormOpen(false);
     }}
    />
   </Modal>

   <ConfirmDialog
    isOpen={!!pending}
    onClose={() => setPending(null)}
    onConfirm={() => {
     if (!pending) return;
     (pending.action === 'archive' ? archive : restore).mutate(pending.unit.id);
    }}
    title={pending?.action === 'restore' ? 'Restore unit?' : 'Archive unit?'}
    description={pending && (
     <>
      <span className="font-mono font-medium text-primary">{pending.unit.code}</span>
      {pending.action === 'restore'
       ? ' will be available for new records again.'
       : ' will be hidden from new records. Referenced units cannot be archived.'}
     </>
    )}
    confirmLabel={pending?.action === 'restore' ? 'Restore' : 'Archive'}
    variant={pending?.action === 'restore' ? 'primary' : 'danger'}
    pending={archive.isPending || restore.isPending}
   />
  </div>
 );
}

function UomForm({ unit, onClose, onSaved }: { unit: UomOption | null; onClose: () => void; onSaved: () => void }) {
 const {
  register,
  handleSubmit,
  setError,
  formState: { errors, isSubmitting },
 } = useForm<FormValues>({
  resolver: zodResolver(schema),
  defaultValues: { code: unit?.code ?? '', name: unit?.name ?? '' },
 });
 const mutation = useMutation({
  mutationFn: (data: FormValues) => unit ? uomsApi.update(unit.id, data) : uomsApi.create(data),
  onSuccess: () => {
   toast.success(unit ? 'Unit updated.' : 'Unit created.');
   onSaved();
  },
  onError: (e: AxiosError<ApiValidationError>) => {
   if (e.response?.status === 422 && e.response.data?.errors) {
    Object.entries(e.response.data.errors).forEach(([field, messages]) => {
     setError(field as keyof FormValues, { type: 'server', message: Array.isArray(messages) ? messages[0] : String(messages) });
    });
    toast.error('Please fix the highlighted fields.');
   } else {
    toast.error(e.response?.data?.message ?? 'Failed to save unit.');
   }
  },
 });

 return (
  <form onSubmit={handleSubmit((data) => mutation.mutate(data), onFormInvalid<FormValues>())} className="py-3">
   <div className="space-y-3">
    <Input label="Code" required autoFocus maxLength={20} {...register('code')} error={errors.code?.message} />
    <Input label="Name" required maxLength={80} {...register('name')} error={errors.name?.message} />
   </div>
   <ModalFooter>
    <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
    <Button type="submit" variant="primary" loading={mutation.isPending} disabled={mutation.isPending || isSubmitting}>
     {unit ? 'Save changes' : 'Create unit'}
    </Button>
   </ModalFooter>
  </form>
 );
}
