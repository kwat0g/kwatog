import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { invoicesApi, type InvoiceListParams } from '@/api/accounting/invoices';
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
import type { Invoice } from '@/types/accounting';

export default function InvoicesPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<InvoiceListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['accounting', 'invoices', filters],
    queryFn: () => invoicesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Invoice>[] = [
    { key: 'invoice_number', header: 'Invoice no',
      cell: (r) => <Link to={`/accounting/invoices/${r.id}`} className="font-mono text-accent hover:underline">{r.invoice_number ?? 'DRAFT'}</Link> },
    { key: 'customer', header: 'Customer', cell: (r) => r.customer?.name ?? '—' },
    { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
    { key: 'due_date', header: 'Due', cell: (r) => <NumCell className={r.is_overdue ? 'text-danger-fg' : undefined}>{formatDate(r.due_date)}</NumCell> },
    { key: 'total', header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
    { key: 'balance', header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.display_status)}>{r.display_status}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'draft', label: 'Draft' }, { value: 'finalized', label: 'Finalized' },
        { value: 'partial', label: 'Partial' }, { value: 'paid', label: 'Paid' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
    { key: 'overdue', label: 'Overdue', type: 'select', options: [{ value: '', label: 'All' }, { value: '1', label: 'Overdue only' }] },
  ];

  return (
    <div>
      <PageHeader
        title="Invoices (AR)"
        subtitle={data ? `${data.meta.total} invoices` : undefined}
        actions={can('accounting.invoices.create') ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/accounting/invoices/create')}>New invoice</Button>
        ) : null}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search invoice no or customer…"
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load invoices" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No invoices yet"
          description={can('accounting.invoices.create') ? 'Issue invoices to track receivables.' : 'Nothing here yet.'}
          action={can('accounting.invoices.create') ? <Button variant="primary" onClick={() => navigate('/accounting/invoices/create')}>New invoice</Button> : undefined} />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4"><DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
      )}
    </div>
  );
}
