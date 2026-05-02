import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, Star, StarOff, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { approvedSuppliersApi } from '@/api/purchasing/approved-suppliers';
import { Button } from '@/components/ui/Button';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Switch } from '@/components/ui/Switch';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { ApprovedSupplier } from '@/types/purchasing';

export default function ApprovedSuppliersPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<any>({ page: 1, per_page: 50 });
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState({ item_id: '', vendor_id: '', is_preferred: false, lead_time_days: 14, last_price: '' });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'approved-suppliers', filters],
    queryFn: () => approvedSuppliersApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const create = useMutation({
    mutationFn: () => approvedSuppliersApi.create({
      ...draft, last_price: draft.last_price || undefined,
    }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'approved-suppliers'] }); toast.success('Supplier added.'); setOpen(false); },
    onError: () => toast.error('Failed.'),
  });
  const togglePreferred = useMutation({
    mutationFn: (s: ApprovedSupplier) => approvedSuppliersApi.update(s.id, { is_preferred: !s.is_preferred }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['purchasing', 'approved-suppliers'] }),
  });
  const del = useMutation({
    mutationFn: (s: ApprovedSupplier) => approvedSuppliersApi.delete(s.id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['purchasing', 'approved-suppliers'] }); toast.success('Removed.'); },
  });

  const columns: Column<ApprovedSupplier>[] = [
    { key: 'item', header: 'Item', cell: (r) => <span className="font-mono">{r.item.code}</span> },
    { key: 'name', header: 'Name', cell: (r) => r.item.name },
    { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor.name },
    { key: 'preferred', header: 'Preferred', cell: (r) => (
      <button onClick={() => togglePreferred.mutate(r)} className="text-text-muted hover:text-accent">
        {r.is_preferred ? <Star size={16} fill="currentColor" className="text-accent" /> : <StarOff size={16} />}
      </button>
    ) },
    { key: 'lead', header: 'Lead time', align: 'right', cell: (r) => <NumCell>{r.lead_time_days}d</NumCell> },
    { key: 'price', header: 'Last price', align: 'right', cell: (r) => <NumCell>{r.last_price ? formatPeso(r.last_price) : '—'}</NumCell> },
    { key: 'actions', header: '', cell: (r) => (
      <button onClick={() => del.mutate(r)} className="text-text-muted hover:text-danger"><Trash2 size={12} /></button>
    ) },
  ];

  return (
    <div>
      <PageHeader title="Approved suppliers"
        actions={can('purchasing.po.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setOpen(true)}>Link supplier</Button>
        ) : null} />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && <EmptyState icon="inbox" title="No approved suppliers yet" />}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f: any) => ({ ...f, page }))} />
        </div>
      )}
      <Modal isOpen={open} onClose={() => setOpen(false)} title="Link item to vendor" size="sm">
        <div className="space-y-3">
          <Input label="Item ID (hash)" required value={draft.item_id}
                 onChange={(e) => setDraft({ ...draft, item_id: e.target.value })} className="font-mono" />
          <Input label="Vendor ID (hash)" required value={draft.vendor_id}
                 onChange={(e) => setDraft({ ...draft, vendor_id: e.target.value })} className="font-mono" />
          <Input label="Lead time (days)" type="number" min={0} max={365} value={draft.lead_time_days}
                 onChange={(e) => setDraft({ ...draft, lead_time_days: Number(e.target.value) })} className="font-mono tabular-nums text-right" />
          <Input label="Last price" value={draft.last_price}
                 onChange={(e) => setDraft({ ...draft, last_price: e.target.value })} className="font-mono tabular-nums text-right" />
          <Switch label="Preferred supplier" checked={draft.is_preferred}
                  onChange={(e) => setDraft({ ...draft, is_preferred: e.target.checked })} />
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-default mt-4">
          <Button variant="secondary" onClick={() => setOpen(false)}>Cancel</Button>
          <Button variant="primary" onClick={() => create.mutate()} disabled={!draft.item_id || !draft.vendor_id || create.isPending} loading={create.isPending}>
            Add
          </Button>
        </div>
      </Modal>
    </div>
  );
}
