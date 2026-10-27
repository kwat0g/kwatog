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
import { manilaIsoDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { PricingNavigation } from './PricingNavigation';

export default function PriceAgreementsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('crm.price_agreements.propose');
  const [filters, setFilters] = useUrlFilters<PriceAgreementListParams>({
    search: '',
    agreement: '',
    page: 1,
    per_page: 25,
  });
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
      key: 'product',
      header: 'Product',
      className: 'min-w-44',
      cell: (r) =>
        r.product ? (
          <div>
            <span className="font-mono">{r.product.part_number}</span> — {r.product.name}
          </div>
        ) : (
          <span className="text-muted">—</span>
        ),
    },
    {
      key: 'customer',
      header: 'Customer',
      className: 'min-w-36',
      cell: (r) => r.customer?.name ?? '—',
    },
    {
      key: 'price',
      header: 'Price',
      className: 'min-w-32',
      align: 'right',
      cell: (r) => (
        <NumCell>
          {formatPeso(r.price)}
          <span className="block text-xs text-muted">
            {r.pricing_method === 'tiered' ? 'Saved fallback price' : 'Any quantity'} · VAT excluded
          </span>
        </NumCell>
      ),
    },
    {
      key: 'quantity',
      header: 'Quantity and unit price',
      className: 'min-w-44',
      cell: (r) =>
        r.pricing_method === 'tiered' && r.tiers?.length ? (
          <div className="space-y-1 text-sm">
            <div>
              Below {r.tiers[0].min_qty}: {formatPeso(r.price)}
            </div>
            {r.tiers.map((tier) => (
              <div key={tier.min_qty} className="tabular-nums">
                {tier.min_qty}+ {r.product?.unit_of_measure}: {formatPeso(tier.unit_price)}
              </div>
            ))}
            <div className="text-xs text-muted">
              Per product, per order. Highest eligible tier applies.
            </div>
          </div>
        ) : (
          <span className="text-muted">No minimum quantity</span>
        ),
    },
    {
      key: 'review',
      header: 'Price review',
      className: 'min-w-48',
      cell: (r) => (
        <div className="max-w-64 space-y-1.5 text-xs">
          {r.pricing_warnings?.length ? (
            <>
              <Chip
                variant={
                  r.pricing_warnings.some((warning) =>
                    warning.includes('new quotes at this price are blocked'),
                  )
                    ? 'danger'
                    : 'warning'
                }
              >
                {r.pricing_warnings.some((warning) =>
                  warning.includes('new quotes at this price are blocked'),
                )
                  ? 'New quotes blocked'
                  : 'Review needed'}
              </Chip>
              <details>
                <summary className="cursor-pointer text-muted hover:text-primary focus-visible:outline-accent">
                  {r.pricing_warnings.length} {r.pricing_warnings.length === 1 ? 'issue' : 'issues'}{' '}
                  · Price details
                </summary>
                <div className="mt-2 space-y-2">
                  <div className="text-muted">
                    Base: {formatPeso(r.product?.base_selling_price, 'Not configured')}
                    <br />
                    Minimum: {formatPeso(r.product?.minimum_selling_price, 'Not configured')}
                  </div>
                  <ul className="list-disc space-y-1 pl-4 text-warning-fg">
                    {r.pricing_warnings.map((warning) => (
                      <li key={warning}>{warning}</li>
                    ))}
                  </ul>
                </div>
              </details>
            </>
          ) : (
            <>
              <span className="text-muted">No price warnings</span>
              <div className="text-muted">
                Base {formatPeso(r.product?.base_selling_price, 'Not configured')} · Min.{' '}
                {formatPeso(r.product?.minimum_selling_price, 'Not configured')}
              </div>
            </>
          )}
        </div>
      ),
    },
    {
      key: 'effective_from',
      header: 'From',
      className: 'whitespace-nowrap',
      align: 'right',
      cell: (r) => <NumCell>{r.effective_from}</NumCell>,
    },
    {
      key: 'effective_to',
      header: 'To',
      className: 'whitespace-nowrap',
      align: 'right',
      cell: (r) => <NumCell>{r.effective_to}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) =>
        r.deleted_at ? (
          <Chip variant="neutral">Archived</Chip>
        ) : r.is_currently_active ? (
          <Chip variant="success">Active</Chip>
        ) : r.effective_from > manilaIsoDate() ? (
          <Chip variant="info">Scheduled</Chip>
        ) : (
          <Chip variant="neutral">Expired</Chip>
        ),
    },
    ...(canManage
      ? [
          {
            key: 'actions',
            header: '',
            align: 'right' as const,
            cell: (r: PriceAgreement) =>
              r.deleted_at ? (
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
                    aria-label="Propose replacement agreement"
                  >
                    <LuPencil size={14} />
                  </Link>
                  {/* Manila, not the viewer's zone: the backend expires agreements on the
                      Philippine calendar date (APP_TIMEZONE=Asia/Manila), so a browser set to
                      UTC would otherwise offer Archive a day late. */}
                  {r.effective_to < manilaIsoDate() && (
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
                  )}
                </div>
              ),
          },
        ]
      : []),
  ];

  return (
    <div>
      <PageHeader
        title="Price agreements"
        subtitle={
          data
            ? `${data.meta.total} ${data.meta.total === 1 ? 'agreement' : 'agreements'}`
            : undefined
        }
        actions={
          canManage ? (
            <Button
              variant="primary"
              icon={<LuPlus size={14} />}
              onClick={() => navigate('/crm/price-agreements/create')}
            >
              Propose agreement
            </Button>
          ) : null
        }
      />
      <PricingNavigation current="agreements" />
      <FilterBar
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search product or customer…"
        actions={<ArchiveFilter value={scope} onChange={setScope} />}
      />
      {isLoading && !data && <SkeletonTable columns={canManage ? 9 : 8} rows={8} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load price agreements"
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="dollar-sign"
          title={scope === 'only' ? 'No archived price agreements' : 'No price agreements yet'}
          description={
            canManage
              ? 'Create a price agreement to set customer-specific pricing.'
              : 'Nothing here yet.'
          }
          action={
            canManage && scope !== 'only' ? (
              <Button variant="primary" onClick={() => navigate('/crm/price-agreements/create')}>
                Propose agreement
              </Button>
            ) : undefined
          }
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
        onConfirm={() => {
          if (confirmDelete) del.mutate(confirmDelete.id);
        }}
        title="Archive price agreement?"
        description={
          confirmDelete ? (
            <>
              This will archive the agreement for{' '}
              <span className="font-medium text-primary">
                {confirmDelete.customer?.name ?? 'the selected customer'}
              </span>{' '}
              and it can be restored later.
            </>
          ) : null
        }
        confirmLabel="Archive"
        variant="danger"
        pending={del.isPending}
      />
    </div>
  );
}
