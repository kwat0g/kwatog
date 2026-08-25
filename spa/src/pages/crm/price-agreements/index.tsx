import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { Link, useNavigate } from 'react-router-dom';
import { LuArchiveRestore, LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import { priceAgreementsApi, type PriceAgreementListParams } from '@/api/crm/priceAgreements';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { PriceAgreement } from '@/types/crm';
import { formatPeso } from '@/lib/formatNumber';

export default function PriceAgreementsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('crm.price_agreements.manage');
  const [filters, setFilters] = useUrlFilters<PriceAgreementListParams>({ search: '', page: 1, per_page: 25 });
  const [scope, setScope] = useState<ArchiveScope>('active');
  const [confirmDelete, setConfirmDelete] = useState<PriceAgreement | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'price-agreements', filters, { trashed: archiveToTrashed(scope) }],
    queryFn: () => priceAgreementsApi.list({ ...filters, trashed: archiveToTrashed(scope) }),
    placeholderData: (prev) => prev,
  });

  const del = useMutation({
    mutationFn: (id: string) => priceAgreementsApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      toast.success('Price agreement archived.');
      setConfirmDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to archive price agreement.');
    },
  });

  const restore = useMutation({
    mutationFn: (id: string) => priceAgreementsApi.restore(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'price-agreements'] });
      toast.success('Price agreement restored.');
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to restore price agreement.');
    },
  });

  const columns: Column<PriceAgreement>[] = [
    {
      key: 'product', header: 'Product',
      cell: (r) => r.product
        ? <div><span className="font-mono">{r.product.part_number}</span> — {r.product.name}</div>
        : <span className="text-muted">—</span>,
    },
    { key: 'customer', header: 'Customer', cell: (r) => r.customer?.name ?? '—' },
    {
      key: 'price', header: 'Price', align: 'right',
      cell: (r) => <NumCell>{r.pricing_method === 'tiered' ? `${r.pricing_method_label} · ${formatPeso(r.price)}` : formatPeso(r.price)}</NumCell>,
    },
    {
      key: 'effective_from', header: 'From', align: 'right',
      cell: (r) => <NumCell>{r.effective_from}</NumCell>,
    },
    {
      key: 'effective_to', header: 'To', align: 'right',
      cell: (r) => <NumCell>{r.effective_to}</NumCell>,
    },
    {
      key: 'status', header: 'Status',
      cell: (r) => r.deleted_at
        ? <Chip variant="neutral">Archived</Chip>
        : r.is_currently_active
          ? <Chip variant="success">Active</Chip>
          : <Chip variant="neutral">Expired</Chip>,
    },
    ...(canManage ? [{
      key: 'actions', header: '', align: 'right' as const,
      cell: (r: PriceAgreement) => r.deleted_at ? (
        <Button
          type="button"
          variant="ghost"
          size="sm"
          iconOnly
          icon={<LuArchiveRestore size={14} />}
          aria-label={`Restore agreement for ${r.customer?.name ?? 'customer'}`}
          onClick={() => restore.mutate(r.id)}
          className="text-muted hover:text-primary"
        />
      ) : (
        <div className="flex justify-end gap-1">
          <Link
            to={`/crm/price-agreements/${r.id}/edit`}
            className="p-1 rounded text-muted hover:text-primary hover:bg-elevated transition-colors inline-flex items-center justify-center"
            aria-label="Edit agreement"
          >
            <LuPencil size={14} />
          </Link>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            iconOnly
            icon={<LuTrash2 size={14} />}
            aria-label="Archive agreement"
            onClick={() => setConfirmDelete(r)}
            className="text-muted hover:text-danger-fg"
          />
        </div>
      ),
    }] : []),
  ];

  return (
    <div>
      <PageHeader
        title="Price agreements"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'agreement' : 'agreements'}` : undefined}
        actions={canManage ? (
          <Button variant="primary" icon={<LuPlus size={14} />} onClick={() => navigate('/crm/price-agreements/create')}>
            New price agreement
          </Button>
        ) : null}
      />
      <FilterBar
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search product or customer…"
        actions={<ArchiveFilter value={scope} onChange={setScope} />}
      />
      {isLoading && !data && <SkeletonTable columns={canManage ? 7 : 6} rows={8} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load price agreements"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="dollar-sign"
          title={scope === 'only' ? 'No archived price agreements' : 'No price agreements yet'}
          description={canManage ? 'Create a price agreement to set customer-specific pricing.' : 'Nothing here yet.'}
          action={canManage && scope !== 'only' ? (
            <Button variant="primary" onClick={() => navigate('/crm/price-agreements/create')}>New price agreement</Button>
          ) : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
          />
        </div>
      )}
      <ConfirmDialog
        isOpen={!!confirmDelete}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => { if (confirmDelete) del.mutate(confirmDelete.id); }}
        title="Archive price agreement?"
        description={confirmDelete ? (
          <>This will archive the agreement for <span className="font-medium text-primary">{confirmDelete.customer?.name ?? 'the selected customer'}</span> and it can be restored later.</>
        ) : null}
        confirmLabel="Archive"
        variant="danger"
        pending={del.isPending}
      />
    </div>
  );
}
