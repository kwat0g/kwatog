import { useQuery } from '@tanstack/react-query';
import { useNavigate, useSearchParams} from 'react-router-dom';
import { LuPlus, LuPrinter } from '@/lib/icons';
import { invoicesApi, type InvoiceListParams } from '@/api/accounting/invoices';
import { bulkPrint } from '@/api/print';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type BulkAction, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import type { Invoice } from '@/types/accounting';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const DEFAULT_FILTERS: InvoiceListParams = {
 page: 1, per_page: 25, status: 'unpaid',
};

export default function InvoicesPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 // Bound to the URL so dashboard drill-downs (?status=finalized) arrive
 // pre-filtered and the browser back button restores the previous view.
 // Dashboard KPIs pass ?date_from=YYYY-MM-DD but the API filter key is
 // `from` — seed filters.from at mount so the pre-filtered list arrives.
 const [searchParams] = useSearchParams();
 const dateFromParam = searchParams.get('date_from') ?? undefined;
 const [filters, setFilters] = useUrlFilters<InvoiceListParams>({ ...DEFAULT_FILTERS, from: dateFromParam });

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['accounting', 'invoices', filters],
 queryFn: () => invoicesApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: invoiceOptions } = useQuery({
 queryKey: ['accounting', 'invoices', 'options'],
 queryFn: invoicesApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabels = new Map((invoiceOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');
 const outstandingBalance = data?.data.some((invoice) => invoice.balance != null)
  ? formatPeso(data.data.reduce((sum, invoice) => sum + Number(invoice.balance ?? 0), 0))
  : '—';

 const columns: Column<Invoice>[] = [
 { key: 'invoice_number', header: 'Invoice no',
 cell: (r) => <span className="font-mono">{r.invoice_number ?? 'DRAFT'}</span> },
 { key: 'customer', header: 'Customer', cell: (r) => r.customer?.name ?? '—' },
 { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
 { key: 'due_date', header: 'Due', cell: (r) => <NumCell className={r.is_overdue ? 'text-danger-fg' : undefined}>{formatDate(r.due_date)}</NumCell> },
 { key: 'total', header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
 { key: 'balance', header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.display_status)}>{statusLabels.get(r.display_status) ?? r.display_status}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(invoiceOptions?.statuses ?? []),
 ] },
 { key: 'overdue', label: 'Overdue', type: 'select', options: [{ value: '', label: 'All' }, { value: '1', label: 'Overdue only' }] },
 ];

 return (
 <div>
 <PageHeader
 title="Invoices (AR)"
 subtitle={data ? `${data.meta.total} invoices` : undefined}
 actions={can('accounting.invoices.create') ? (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/accounting/invoices/create')}>New invoice</Button>
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
 {data && (
  <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">
  <StatCard
    label={statusLabel('draft')}
    value={data.data.filter(i => i.display_status === 'draft').length}
    helper="in current view"
    linkTo="?status=draft"
  />
  <StatCard
    label={statusLabel('unpaid')}
    value={data.data.filter(i => i.display_status === 'unpaid').length}
    helper="in current view"
    linkTo="?status=unpaid"
  />
  <StatCard
    label={statusLabel('overdue')}
    value={data.data.filter(i => i.is_overdue).length}
    helper="in current view"
    linkTo="?overdue=1"
    className="border-danger/30 bg-danger-bg/20"
  />
  <StatCard
    label="Outstanding Balance"
    value={outstandingBalance}
    helper="in current view"
  />
  </div>
  )}

{data && data.data.length === 0 && (
 <ListEmptyState />
 )}



 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable
  tableKey="accounting-invoices"
  onRowClick={(r) => navigate(`/accounting/invoices/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 selectable
 bulkActions={[{
 label: 'Print PDFs',
 icon: <LuPrinter size={14} />,
 onClick: (rows) => bulkPrint('invoice', rows.map((r) => r.id)) } as BulkAction<Invoice>]}
 />
 </div>
 )}
 </div>
 );
}
