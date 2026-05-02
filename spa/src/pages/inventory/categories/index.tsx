import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { itemCategoriesApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
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

// ──────────────────────────────────────────────────────────────────────────────
// Page
// ──────────────────────────────────────────────────────────────────────────────

export default function ItemCategoriesPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.items.manage');

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<ItemCategory | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<FlatRow | null>(null);

  const tree = useQuery({
    queryKey: ['inventory', 'categories', 'tree'],
    queryFn: () => itemCategoriesApi.tree(),
  });
  const flatList = useQuery({
    queryKey: ['inventory', 'categories'],
    queryFn: () => itemCategoriesApi.list(),
  });

  const rows: FlatRow[] = useMemo(
    () => (tree.data ? flatten(tree.data) : []),
    [tree.data],
  );

  const del = useMutation({
    mutationFn: (id: string) => itemCategoriesApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'categories'] });
      toast.success('Category deleted.');
      setConfirmDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to delete category.');
    },
  });

  const columns: Column<FlatRow>[] = [
    {
      key: 'name',
      header: 'Name',
      cell: (r) => (
        <span style={{ paddingLeft: r.depth * 16 }} className="inline-flex items-center gap-1.5">
          {r.depth > 0 && <span className="text-text-muted">└</span>}
          <span className="font-medium">{r.name}</span>
        </span>
      ),
    },
    {
      key: 'parent',
      header: 'Parent',
      cell: (r) => r.parent_name ?? <span className="text-text-muted">—</span>,
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      cell: (r) =>
        canManage ? (
          <div className="flex justify-end gap-1">
            <button
              type="button"
              onClick={() => {
                const node = findById(tree.data ?? [], r.id);
                if (node) {
                  setEditing(node);
                  setFormOpen(true);
                }
              }}
              className="p-1.5 text-text-muted hover:text-primary hover:bg-elevated rounded-sm transition-colors"
              aria-label={`Edit ${r.name}`}
            >
              <Pencil size={14} />
            </button>
            <button
              type="button"
              onClick={() => setConfirmDelete(r)}
              className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm transition-colors"
              aria-label={`Delete ${r.name}`}
            >
              <Trash2 size={14} />
            </button>
          </div>
        ) : null,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Item categories"
        subtitle={tree.data ? `${rows.length} ${rows.length === 1 ? 'category' : 'categories'}` : undefined}
        actions={
          canManage ? (
            <Button
              variant="primary"
              size="sm"
              icon={<Plus size={14} />}
              onClick={() => {
                setEditing(null);
                setFormOpen(true);
              }}
            >
              New category
            </Button>
          ) : null
        }
      />

      {tree.isLoading && !tree.data && (
        <div className="px-5 py-4">
          <SkeletonTable columns={3} rows={6} />
        </div>
      )}

      {tree.isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load categories"
          action={
            <Button variant="secondary" onClick={() => tree.refetch()}>
              Retry
            </Button>
          }
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

      {tree.data && rows.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={rows} />
        </div>
      )}

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
                <> has sub-categories. Deleting it will fail until they are removed.</>
              ) : (
                <> will be permanently removed. This cannot be undone.</>
              )}
            </>
          ) : null
        }
        confirmLabel="Delete"
        variant="danger"
        pending={del.isPending}
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
          placeholder="e.g. Raw Materials"
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
      <div className="flex justify-end gap-2 pt-3 mt-4 border-t border-default">
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
      </div>
    </form>
  );
}

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

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
