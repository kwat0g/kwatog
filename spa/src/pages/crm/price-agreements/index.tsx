import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { priceAgreementsApi, type PriceAgreementListParams } from '@/api/crm/priceAgreements';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { PriceAgreement } from '@/types/crm';

/**
 * Sprint 6 Task 47 — Price agreements list (read-only for now).
 * The "create / edit" workflows live inside the customer or product detail
 * pages in a follow-up; this index is the global lookup view.
 */
export default function PriceAgreementsListPage() {
  const [filters, setFilters] = useState<PriceAgreementListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'price-agreements', filters],
    queryFn: () => priceAgreementsApi.list(filters),
    placeholderData: (prev) => prev,
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
      cell: (r) => <NumCell>₱ {Number(r.price).toFixed(2)}</NumCell>,
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
      cell: (r) => r.is_currently_active
        ? <Chip variant="success">Active</Chip>
        : <Chip variant="neutral">Expired</Chip>,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Price agreements"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'agreement' : 'agreements'}` : undefined}
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={8} />}
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
          title="No price agreements yet"
          description="Open a customer or product to create one."
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
    </div>
  );
}
