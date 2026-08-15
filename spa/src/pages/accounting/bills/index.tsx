import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuPlus, LuPrinter } from '@/lib/icons';
import { billsApi, type BillListParams } from '@/api/accounting/bills';
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
import type { Bill } from '@/types/accounting';

const DEFAULT_FILTERS: BillListParams = {
 page: 1, per_page: 25, status: 'unpaid',
};

export default function BillsPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 // Bound to the URL so dashboard drill-downs (?status=unpaid) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<BillListParams>(DEFAULT_FILTERS);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['accounting', 'bills', filters],
 queryFn: () => billsApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: billOptions } = useQuery({
 queryKey: ['accounting', 'bills', 'options'],
 queryFn: billsApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabels = new Map((billOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');
 const outstandingBalance = data?.data.some((bill) => bill.balance != null)
  ? formatPeso(data.data.reduce((sum, bill) => sum + Number(bill.balance ?? 0), 0))
  : '—';

 const columns: Column<Bill>[] = [
 { key: 'bill_number', header: 'Bill no', cell: (r) => <span className="font-mono">{r.bill_number}</span> },
 { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor?.name ?? '—' },
 { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
 { key: 'due_date', header: 'Due', cell: (r) => <NumCell className={r.is_overdue ? 'text-danger-fg' : undefined}>{formatDate(r.due_date)}</NumCell> },
 { key: 'total', header: 'Total', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
 { key: 'balance', header: 'Balance', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.balance)}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(billOptions?.statuses ?? []),
 ] },
 {
 key: 'overdue', label: 'Overdue', type: 'select',
 options: [{ value: '', label: 'All' }, { value: '1', label: 'Overdue only' }] },
 ];

 return (
 <div>
 <PageHeader
 title="Bills (AP)"
 subtitle={data ? `${data.meta.total} bills` : undefined}
 actions={can('accounting.bills.create') ? (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/accounting/bills/create')}>New bill</Button>
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
 {data && (
 <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">

  <StatCard
    label={statusLabel('unpaid')}
    value={data.data.filter(i => i.status === 'unpaid').length}
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
 <EmptyState icon="inbox" title="No bills yet"
 description={can('accounting.bills.create') ? 'Record vendor bills to track payables.' : 'Nothing here yet.'}
 action={can('accounting.bills.create') ? <Button variant="primary" onClick={() => navigate('/accounting/bills/create')}>New bill</Button> : undefined} />
 )}

 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable
  tableKey="accounting-bills"
  onRowClick={(r) => navigate(`/accounting/bills/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 selectable
 bulkActions={[{
 label: 'Print PDFs',
 icon: <LuPrinter size={14} />,
 onClick: (rows) => bulkPrint('bill', rows.map((r) => r.id)) } as BulkAction<Bill>]}
 />
 </div>
 )}
 </div>
 );
}
