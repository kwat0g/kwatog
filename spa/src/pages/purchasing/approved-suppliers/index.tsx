import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Plus, Star, StarOff, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { approvedSuppliersApi } from '@/api/purchasing/approved-suppliers';
import { itemsApi } from '@/api/inventory/items';
import { vendorsApi } from '@/api/accounting/vendors';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Switch } from '@/components/ui/Switch';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { numberInputProps } from '@/lib/numberInput';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { ListParams } from '@/types';
import type { ApprovedSupplier } from '@/types/purchasing';

const schema = z.object({
  item_id: z.string().min(1, 'Item is required.'),
  vendor_id: z.string().min(1, 'Vendor is required.'),
  is_preferred: z.boolean().default(false),
  lead_time_days: z.coerce.number().int().min(0).max(365),
  last_price: z.string().regex(/^(\d+(\.\d{1,2})?)?$/, 'Up to 2 decimals.').optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

export default function ApprovedSuppliersPage() {
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('purchasing.po.create');
  const [filters, setFilters] = useState<ListParams>({ page: 1, per_page: 50 });
  const [open, setOpen] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<ApprovedSupplier | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['purchasing', 'approved-suppliers', filters],
    queryFn: () => approvedSuppliersApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const togglePreferred = useMutation({
    mutationFn: (s: ApprovedSupplier) => approvedSuppliersApi.update(s.id, { is_preferred: !s.is_preferred }),
    onSuccess: (_, s) => {
      qc.invalidateQueries({ queryKey: ['purchasing', 'approved-suppliers'] });
      toast.success(s.is_preferred ? 'Removed as preferred.' : 'Marked as preferred.');
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to update.'),
  });
  const del = useMutation({
    mutationFn: (s: ApprovedSupplier) => approvedSuppliersApi.delete(s.id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['purchasing', 'approved-suppliers'] });
      toast.success('Approved supplier removed.');
      setConfirmDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) =>
      toast.error(e.response?.data?.message ?? 'Failed to remove.'),
  });

  const columns: Column<ApprovedSupplier>[] = [
    { key: 'item', header: 'Item', cell: (r) => <span className="font-mono">{r.item.code}</span> },
    { key: 'name', header: 'Name', cell: (r) => r.item.name },
    { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor.name },
    { key: 'preferred', header: 'Preferred', cell: (r) => (
      <button
        type="button"
        onClick={() => togglePreferred.mutate(r)}
        disabled={!canManage || togglePreferred.isPending}
        className="text-text-muted hover:text-accent disabled:opacity-50"
        aria-label={r.is_preferred ? 'Unmark preferred' : 'Mark as preferred'}
      >
        {r.is_preferred ? <Star size={16} fill="currentColor" className="text-accent" /> : <StarOff size={16} />}
      </button>
    ) },
    { key: 'lead', header: 'Lead time', align: 'right', cell: (r) => <NumCell>{r.lead_time_days}d</NumCell> },
    { key: 'price', header: 'Last price', align: 'right', cell: (r) => <NumCell>{r.last_price ? formatPeso(r.last_price) : '—'}</NumCell> },
    ...(canManage ? [{
      key: 'actions',
      header: '',
      align: 'right' as const,
      cell: (r: ApprovedSupplier) => (
        <button
          type="button"
          onClick={() => setConfirmDelete(r)}
          className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm transition-colors"
          aria-label="Remove approved supplier"
        >
          <Trash2 size={14} />
        </button>
      ),
    }] : []),
  ];

  return (
    <div>
      <PageHeader
        title="Approved suppliers"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'link' : 'links'}` : undefined}
        actions={canManage ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setOpen(true)}>
            Link supplier
          </Button>
        ) : null}
      />
      {isLoading && !data && <SkeletonTable columns={canManage ? 7 : 6} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No approved suppliers yet"
          description={canManage ? 'Link items to vendors so the auto-replenishment service knows where to source from.' : 'Nothing here yet.'}
          action={canManage ? <Button variant="primary" onClick={() => setOpen(true)}>Link supplier</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
          />
        </div>
      )}

      <Modal isOpen={open} onClose={() => setOpen(false)} title="Link item to vendor" size="sm">
        <ApprovedSupplierForm
          onClose={() => setOpen(false)}
          onSaved={() => {
            qc.invalidateQueries({ queryKey: ['purchasing', 'approved-suppliers'] });
            setOpen(false);
          }}
        />
      </Modal>

      <ConfirmDialog
        isOpen={!!confirmDelete}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => { if (confirmDelete) del.mutate(confirmDelete); }}
        title="Remove approved supplier?"
        description={
          confirmDelete ? (
            <>
              <span className="font-mono font-medium text-primary">{confirmDelete.item.code}</span>
              {' ↔ '}
              <span className="font-medium text-primary">{confirmDelete.vendor.name}</span>
              <br />
              Auto-replenishment for this item will fall back to alternate suppliers (or fail if none).
            </>
          ) : null
        }
        confirmLabel="Remove"
        variant="danger"
        pending={del.isPending}
      />
    </div>
  );
}

function ApprovedSupplierForm({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const items = useQuery({
    queryKey: ['inventory', 'items', { per_page: 200, is_active: 'true' }],
    queryFn: () => itemsApi.list({ per_page: 200, is_active: 'true' }),
  });
  const vendors = useQuery({
    queryKey: ['accounting', 'vendors', { per_page: 200, is_active: 'true' }],
    queryFn: () => vendorsApi.list({ per_page: 200, is_active: 'true' }),
  });

  const { register, handleSubmit, setError, formState: { errors, isSubmitting } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { is_preferred: false, lead_time_days: 14, last_price: '' },
  });

  const m = useMutation({
    mutationFn: (d: FormValues) => approvedSuppliersApi.create({
      item_id: d.item_id,
      vendor_id: d.vendor_id,
      is_preferred: d.is_preferred,
      lead_time_days: d.lead_time_days,
      last_price: d.last_price || undefined,
    }),
    onSuccess: () => { toast.success('Approved supplier linked.'); onSaved(); },
    onError: (e) => { applyServerValidationErrors(e, setError, 'Failed to link supplier.'); },
  });

  return (
    <form onSubmit={handleSubmit((d) => m.mutate(d), onFormInvalid<FormValues>())} className="py-3">
      <div className="space-y-3">
        <Select label="Item" required {...register('item_id')} error={errors.item_id?.message}>
          <option value="">Select item…</option>
          {items.data?.data.map((it) => (
            <option key={it.id} value={it.id}>{it.code} — {it.name}</option>
          ))}
        </Select>
        <Select label="Vendor" required {...register('vendor_id')} error={errors.vendor_id?.message}>
          <option value="">Select vendor…</option>
          {vendors.data?.data.map((v) => (
            <option key={v.id} value={v.id}>{v.name}</option>
          ))}
        </Select>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Lead time (days)"
            type="number"
            min={0}
            max={365}
            required
            {...register('lead_time_days')}
            className="font-mono tabular-nums text-right"
            error={errors.lead_time_days?.message}
          />
          <Input
            label="Last price (₱)"
            {...register('last_price')}
            {...numberInputProps()}
            className="font-mono tabular-nums text-right"
            error={errors.last_price?.message}
            placeholder="optional"
          />
        </div>
        <Switch label="Preferred supplier" {...register('is_preferred')} />
      </div>
      <div className="flex justify-end gap-2 pt-3 mt-4 border-t border-default">
        <Button type="button" variant="secondary" onClick={onClose} disabled={m.isPending}>Cancel</Button>
        <Button type="submit" variant="primary" loading={m.isPending} disabled={m.isPending || isSubmitting}>
          Add
        </Button>
      </div>
    </form>
  );
}
