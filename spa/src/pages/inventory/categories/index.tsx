import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { LuArchiveRestore, LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import toast from 'react-hot-toast';
import { itemCategoriesApi } from '@/api/inventory/items';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { Input } from '@/components/ui/Input';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { onFormInvalid } from '@/lib/formErrors';
import { usePermission } from '@/hooks/usePermission';
import type { ApiValidationError } from '@/types';
import type { ItemCategory } from '@/types/inventory';

// ──────────────────────────────────────────────────────────────────────────────
// Validation schema
// ──────────────────────────────────────────────────────────────────────────────

const schema = z.object({
 name: z
 .string()
 .trim()
 .min(2, 'Name must be at least 2 characters.')
 .max(100, 'Name must be at most 100 characters.'),
 parent_id: z.string().optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

interface FlatRow {
 id: string;
 name: string;
 parent_name: string | null;
 depth: number;
 hasChildren: boolean;
 parent_id: string | null;
}

// Flatten nested tree → indented rows for the DataTable.
function flatten(nodes: ItemCategory[], depth = 0, parentName: string | null = null): FlatRow[] {
 const out: FlatRow[] = [];
 for (const n of nodes) {
  out.push({
   id: n.id,
   name: n.name,
   parent_name: parentName,
   depth,
   hasChildren: !!n.children?.length,
   parent_id: n.parent_id,
  });
  if (n.children?.length) {
   out.push(...flatten(n.children, depth + 1, n.name));
  }
 }
 return out;
}

function findById(nodes: ItemCategory[], id: string): ItemCategory | null {
 for (const n of nodes) {
  if (n.id === id) return n;
  if (n.children?.length) {
   const hit = findById(n.children, id);
   if (hit) return hit;
  }
 }
 return null;
}

/**
 * Item-category catalog manager — rendered inside the "Categories" modal on the
 * Items page (2026-08-08). The standalone /inventory/categories route was
 * removed; this file doubles as the modal body (same pattern as
 * LeaveTypesManager). Compact toolbar instead of a PageHeader because it lives
 * inside a dialog. The item create/edit form reads the same
 * `['inventory', 'categories']` query key, so CRUD here refreshes that dropdown.
 */
export function ItemCategoriesManager() {
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('inventory.items.manage');

 const [formOpen, setFormOpen] = useState(false);
 const [editing, setEditing] = useState<ItemCategory | null>(null);
 const [confirmDelete, setConfirmDelete] = useState<FlatRow | null>(null);
 const [confirmRestore, setConfirmRestore] = useState<FlatRow | null>(null);
 const [scope, setScope] = useState<ArchiveScope>('active');
 const [filters, setFilters] = useUrlFilters({ search: '' });

 const tree = useQuery({
  queryKey: ['inventory', 'categories', 'tree', { trashed: archiveToTrashed(scope), search: filters.search }],
  queryFn: () => itemCategoriesApi.tree({ trashed: archiveToTrashed(scope) }),
  placeholderData: (prev) => prev,
 });
 const flatList = useQuery({
  queryKey: ['inventory', 'categories'],
  queryFn: () => itemCategoriesApi.list(),
 });

 const filteredData = useMemo(() => {
  if (!tree.data) return null;
  if (!filters.search) return tree.data;
  const q = filters.search.toLowerCase();
  const filterTree = (nodes: ItemCategory[]): ItemCategory[] => {
   const result: ItemCategory[] = [];
   for (const node of nodes) {
    const matches = node.name.toLowerCase().includes(q);
    const filteredChildren = node.children ? filterTree(node.children) : undefined;
    if (matches || (filteredChildren && filteredChildren.length > 0)) {
     result.push({ ...node, children: filteredChildren });
    }
   }
   return result;
  };
  return filterTree(tree.data);
 }, [tree.data, filters.search]);

 const rows: FlatRow[] = useMemo(
  () => (filteredData ? flatten(filteredData) : []),
  [filteredData],
 );

 const del = useMutation({
  mutationFn: (id: string) => itemCategoriesApi.delete(id),
  onSuccess: () => {
   qc.invalidateQueries({ queryKey: ['inventory', 'categories'] });
   toast.success('Category archived.');
   setConfirmDelete(null);
  },
  onError: (e: AxiosError<{ message?: string }>) => {
   toast.error(e.response?.data?.message ?? 'Failed to delete category.');
  },
 });

 const restore = useMutation({
  mutationFn: (id: string) => itemCategoriesApi.restoreCategory(id),
  onSuccess: () => {
   qc.invalidateQueries({ queryKey: ['inventory', 'categories'] });
   toast.success('Category restored.');
   setConfirmRestore(null);
  },
  onError: (e: AxiosError<{ message?: string }>) => {
   toast.error(e.response?.data?.message ?? 'Failed to restore category.');
  },
 });

 const columns: Column<FlatRow>[] = [
  {
   key: 'name',
   header: 'Name',
   cell: (r) => (
    <span style={{ paddingLeft: r.depth * 16 }} className="inline-flex items-center gap-1.5">
     {r.depth > 0 && <span className="text-muted">└</span>}
     <span className="font-medium">{r.name}</span>
    </span>
   ),
  },
  {
   key: 'parent',
   header: 'Parent',
   cell: (r) => r.parent_name ?? <span className="text-muted">—</span>,
  },
  {
   key: 'actions',
   header: '',
   align: 'right',
   cell: (r) =>
    canManage ? (
     <div className="flex justify-end gap-1">
      <Button
       type="button"
       variant="ghost"
       size="sm"
       iconOnly
       icon={<LuPencil size={14} />}
       aria-label={`Edit ${r.name}`}
       onClick={() => {
        const node = findById(filteredData ?? [], r.id);
        if (node) {
         setEditing(node);
         setFormOpen(true);
        }
       }}
       className="text-muted hover:text-primary"
      />
      <Button
       type="button"
       variant="ghost"
       size="sm"
       iconOnly
       aria-label={`${scope === 'only' ? 'Restore' : 'Delete'} ${r.name}`}
       onClick={() => (scope === 'only' ? setConfirmRestore(r) : setConfirmDelete(r))}
       className={scope === 'only' ? 'text-muted hover:text-primary' : 'text-muted hover:text-danger-fg'}
       icon={scope === 'only' ? <LuArchiveRestore size={14} /> : <LuTrash2 size={14} />}
      />
     </div>
    ) : null,
  },
 ];

 return (
  <div>
  <div className="pb-3">
   <FilterBar
    onSearch={(search) => setFilters((f) => ({ ...f, search }))}
    searchPlaceholder="Search category..."
   />
  </div>
   {/* Compact toolbar — lives inside a dialog, so no PageHeader. */}
   <div className="flex items-center justify-between gap-3 pb-3">
    <div className="text-sm text-muted">
     {rows.length} {rows.length === 1 ? 'category' : 'categories'}
    </div>
    <div className="flex items-center gap-2">
     <ArchiveFilter value={scope} onChange={setScope} />
     {canManage && (
      <Button
       variant="primary"
       size="xs"
       icon={<LuPlus size={14} />}
       onClick={() => {
        setEditing(null);
        setFormOpen(true);
       }}
      >
       New category
      </Button>
     )}
    </div>
   </div>

   {tree.isLoading && !tree.data && <SkeletonTable columns={3} rows={6} />}

   {tree.isError && (
    <EmptyState
     icon="alert-circle"
     title="Failed to load categories"
     action={<Button variant="secondary" onClick={() => tree.refetch()}>Retry</Button>}
    />
   )}

   {tree.data && rows.length === 0 && (
    <EmptyState
     icon="inbox"
     title="No categories yet"
     description={canManage ? 'Add your first category to organise items.' : 'Nothing here yet.'}
     action={
      canManage ? (
       <Button
        variant="primary"
        onClick={() => {
         setEditing(null);
         setFormOpen(true);
        }}
       >
        New category
       </Button>
      ) : undefined
     }
    />
   )}

   {tree.data && rows.length > 0 && <DataTable columns={columns} data={rows} />}

   {/* Create/Edit modal */}
   <Modal
    isOpen={formOpen}
    onClose={() => setFormOpen(false)}
    title={editing ? `Edit ${editing.name}` : 'New category'}
    size="sm"
   >
    <CategoryForm
     mode={editing ? 'edit' : 'create'}
     category={editing}
     options={(flatList.data ?? []).filter((c) => !editing || c.id !== editing.id)}
     onClose={() => setFormOpen(false)}
     onSaved={() => {
      qc.invalidateQueries({ queryKey: ['inventory', 'categories'] });
      setFormOpen(false);
     }}
    />
   </Modal>

   {/* Delete confirmation */}
   <ConfirmDialog
    isOpen={!!confirmDelete}
    onClose={() => setConfirmDelete(null)}
    onConfirm={() => {
     if (confirmDelete) del.mutate(confirmDelete.id);
    }}
    title="Delete category?"
    description={
     confirmDelete ? (
      <>
       <span className="font-medium text-primary">{confirmDelete.name}</span>
       {confirmDelete.hasChildren ? (
        <> has sub-categories. Archiving it will fail until they are removed.</>
       ) : (
        <> will be archived and can be restored later.</>
       )}
      </>
     ) : null
    }
    confirmLabel="Delete"
    variant="danger"
    pending={del.isPending}
   />

   {/* Restore confirmation */}
   <ConfirmDialog
    isOpen={!!confirmRestore}
    onClose={() => setConfirmRestore(null)}
    onConfirm={() => {
     if (confirmRestore) restore.mutate(confirmRestore.id);
    }}
    title="Restore category?"
    description={
     confirmRestore ? (
      <>
       <span className="font-medium text-primary">{confirmRestore.name}</span>
       {' will be restored and available for items again.'}
      </>
     ) : null
    }
    confirmLabel="Restore"
    variant="primary"
    pending={restore.isPending}
   />
  </div>
 );
}

// ──────────────────────────────────────────────────────────────────────────────
// Form
// ──────────────────────────────────────────────────────────────────────────────

interface CategoryFormProps {
 mode: 'create' | 'edit';
 category: ItemCategory | null;
 options: ItemCategory[];
 onClose: () => void;
 onSaved: () => void;
}

function CategoryForm({ mode, category, options, onClose, onSaved }: CategoryFormProps) {
 const {
  register,
  handleSubmit,
  setError,
  formState: { errors, isSubmitting },
 } = useForm<FormValues>({
  resolver: zodResolver(schema),
  defaultValues: {
   name: category?.name ?? '',
   parent_id: category?.parent_id ?? '',
  },
 });

 const mutation = useMutation({
  mutationFn: (d: FormValues) => {
   const payload = {
    name: d.name.trim(),
    parent_id: d.parent_id ? d.parent_id : null,
   };
   return mode === 'create'
    ? itemCategoriesApi.create(payload)
    : itemCategoriesApi.update(category!.id, payload);
  },
  onSuccess: () => {
   toast.success(mode === 'create' ? 'Category created.' : 'Category updated.');
   onSaved();
  },
  onError: (e: AxiosError<ApiValidationError>) => {
   if (e.response?.status === 422 && e.response.data?.errors) {
    Object.entries(e.response.data.errors).forEach(([field, msgs]) => {
     setError(field as keyof FormValues, {
      type: 'server',
      message: Array.isArray(msgs) ? msgs[0] : String(msgs),
     });
    });
    toast.error('Please fix the highlighted fields.');
   } else {
    toast.error(e.response?.data?.message ?? 'Failed to save category.');
   }
  },
 });

 return (
  <form
   onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
   className="py-3"
  >
   <div className="space-y-3">
    <Input
     label="Name"
     required
     autoFocus
     maxLength={100}
     {...register('name')}
     error={errors.name?.message}
     placeholder="Category name"
    />
    <Select label="Parent (optional)" {...register('parent_id')} error={errors.parent_id?.message}>
     <option value="">— Top level —</option>
     {options.map((c) => (
      <option key={c.id} value={c.id}>
       {c.parent_name ? `${c.parent_name} > ${c.name}` : c.name}
      </option>
     ))}
    </Select>
   </div>
   <ModalFooter>
    <Button type="button" variant="secondary" onClick={onClose} disabled={mutation.isPending}>
     Cancel
    </Button>
    <Button
     type="submit"
     variant="primary"
     loading={mutation.isPending}
     disabled={mutation.isPending || isSubmitting}
    >
     {mode === 'create' ? 'Create' : 'Save changes'}
    </Button>
   </ModalFooter>
  </form>
 );
}
