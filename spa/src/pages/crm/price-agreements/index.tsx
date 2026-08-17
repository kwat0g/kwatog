import { FilterBar } from '@/components/ui/FilterBar';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { Link, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuPencil } from '@/lib/icons';
import { priceAgreementsApi, type PriceAgreementListParams } from '@/api/crm/priceAgreements';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { PriceAgreement } from '@/types/crm';
import { formatPeso } from '@/lib/formatNumber';

/**
 * Sprint 6 Task 47 — Price agreements list (read-only for now).
 * The "create / edit" workflows live inside the customer or product detail
 * pages in a follow-up; this index is the global lookup view.
 */
export default function PriceAgreementsListPage() {
 const navigate = useNavigate();
 const [filters, setFilters] = useUrlFilters<PriceAgreementListParams & { search?: string }>({ search: '', page: 1, per_page: 25 });

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
 cell: (r) => <NumCell>{formatPeso(r.price)}</NumCell>,
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
 {
 key: 'actions', header: '',
 cell: (r) => (
 <Link
 to={`/crm/price-agreements/${r.id}/edit`}
 className="p-1 rounded text-muted hover:text-primary hover:bg-elevated transition-colors inline-flex items-center justify-center"
 aria-label="Edit agreement"
 >
 <LuPencil size={14} />
 </Link>
 ),
 },
 ];

 return (
 <div>
 <PageHeader
 title="Price agreements"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'agreement' : 'agreements'}` : undefined}
 actions={
 <Button variant="primary" onClick={() => navigate('/crm/price-agreements/create')}>
 New price agreement
 </Button>
 }
 />
 <FilterBar onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))} searchPlaceholder="Search agreement..." />
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
 description="Create your first price agreement to set customer-specific pricing."
 action={<Button variant="primary" onClick={() => navigate('/crm/price-agreements/create')}>New price agreement</Button>}
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
 </div>
 );
}
