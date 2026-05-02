import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { billsApi, type BillListParams } from '@/api/accounting/bills';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { Bill } from '@/types/accounting';

export default function BillsPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<BillListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'bills', filters],
    queryFn: () => billsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Bill>[] = [
    { key: 'bill_number', header: 'Bill no', cell: (r) => <Link to={`/accounting/bills/${r.id}`} className="font-mono text-accent hover:underline">{r.bill_number}</Link> },
    { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor?.name ?? '—' },
    { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'due_date', header: 'Due', cell: (r) => <NumCell className={r.is_overdue ? 'text-danger-fg' : undefined}>{formatDate(r.due_date)}</NumCell> },
    { key: 'total', header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
    { key: 'balance', header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'unpaid', label: 'Unpaid' }, { value: 'partial', label: 'Partial' },
        { value: 'paid', label: 'Paid' }, { value: 'cancelled', label: 'Cancelled' },
      ],
    },
    {
      key: 'overdue', label: 'Overdue', type: 'select',
      options: [{ value: '', label: 'All' }, { value: '1', label: 'Overdue only' }],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Bills (AP)"
        subtitle={data ? `${data.meta.total} bills` : undefined}
        actions={can('accounting.bills.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/accounting/bills/create')}>New bill</Button>
        ) : null}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search bill no or vendor…"
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load bills" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No bills yet"
          description={can('accounting.bills.create') ? 'Record vendor bills to track payables.' : 'Nothing here yet.'}
          action={can('accounting.bills.create') ? <Button variant="primary" onClick={() => navigate('/accounting/bills/create')}>New bill</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4"><DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
      )}
    </div>
  );
}
