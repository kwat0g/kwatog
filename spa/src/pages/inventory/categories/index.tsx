import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { itemCategoriesApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { ItemCategory } from '@/types/inventory';

export default function ItemCategoriesPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [parentId, setParentId] = useState<string>('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'categories', 'tree'],
    queryFn: () => itemCategoriesApi.tree(),
  });
  const flat = useQuery({
    queryKey: ['inventory', 'categories'],
    queryFn: () => itemCategoriesApi.list(),
  });

  const create = useMutation({
    mutationFn: () => itemCategoriesApi.create({ name, parent_id: parentId ? Number(parentId) : null }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'categories'] });
      toast.success('Category created.');
      setOpen(false); setName(''); setParentId('');
    },
    onError: () => toast.error('Failed to create category.'),
  });

  const del = useMutation({
    mutationFn: (id: string) => itemCategoriesApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'categories'] });
      toast.success('Category deleted.');
    },
    onError: (e: any) => toast.error(e?.response?.data?.message ?? 'Failed.'),
  });

  const renderNode = (n: ItemCategory, depth = 0): JSX.Element => (
    <li key={n.id} className="border-b border-subtle">
      <div className="flex items-center justify-between py-2 px-3" style={{ paddingLeft: `${12 + depth * 20}px` }}>
        <span className="text-sm">{n.name}</span>
        {can('inventory.items.manage') && (
          <button className="text-text-muted hover:text-danger" onClick={() => del.mutate(n.id)} aria-label="Delete">
            <Trash2 size={14} />
          </button>
        )}
      </div>
      {n.children && n.children.length > 0 && (
        <ul>{n.children.map((c) => renderNode(c, depth + 1))}</ul>
      )}
    </li>
  );

  return (
    <div>
      <PageHeader title="Item categories"
        actions={can('inventory.items.manage') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setOpen(true)}>Add category</Button>
        ) : null}
      />
      <div className="px-5 py-4">
        {isLoading && <SkeletonTable columns={1} rows={6} />}
        {isError && <EmptyState icon="alert-circle" title="Failed to load categories" action={<Button onClick={() => refetch()}>Retry</Button>} />}
        {data && data.length === 0 && <EmptyState icon="inbox" title="No categories yet" />}
        {data && data.length > 0 && (
          <div className="border border-default rounded-md bg-canvas">
            <ul>{data.map((n) => renderNode(n))}</ul>
          </div>
        )}
      </div>
      <Modal isOpen={open} onClose={() => setOpen(false)} title="New category" size="sm">
        <div className="space-y-3">
          <Input label="Name" value={name} onChange={(e) => setName(e.target.value)} required />
          <Select label="Parent (optional)" value={parentId} onChange={(e) => setParentId(e.target.value)}>
            <option value="">— Top level —</option>
            {flat.data?.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </Select>
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default mt-4">
          <Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="primary" onClick={() => create.mutate()}
                  disabled={!name || create.isPending} loading={create.isPending}>Create</Button>
        </div>
      </Modal>
    </div>
  );
}
