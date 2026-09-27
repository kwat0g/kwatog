/**
 * My Deliveries — the driver's run sheet.
 *
 * Renders inside the standard app shell (sidebar + topbar) so a driver keeps
 * the same navigation, breadcrumbs and offline banner as every other role.
 * The body adapts to the device it is actually used on: a card per stop under
 * `md` (one thumb, one hand), the shared DataTable above it where a dispatch
 * desk would put it.
 */
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { LuChevronRight, LuRefreshCw } from '@/lib/icons';
import { driverApi } from '@/api/driver';
import type { DriverDelivery, DriverDeliveryStatus } from '@/types/driver';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { TouchCardSkeleton } from '@/components/layout/TouchShell';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { deliveryStatusVariant } from '@/lib/statusVariants';
import { focusRing } from '@/lib/focus';
import { formatDate, localIsoDate } from '@/lib/formatDate';
import type { PaginationMeta } from '@/types';
import { cn } from '@/lib/cn';

type DriverListFilters = {
 page: number;
 per_page: number;
 status: string;
 date_from: string;
 date_to: string;
};

const DEFAULT_FILTERS: DriverListFilters = {
 page: 1,
 per_page: 25,
 status: '',
 date_from: '',
 date_to: '',
};

/**
 * The statuses a driver can act on. Copies the labels from
 * `DeliveryStatus::label()` on the API so the filter reads the same as the chip
 * on the row it selects — `in_transit` is "In transit", not "in transit".
 */
const STATUS_OPTIONS: { value: DriverDeliveryStatus; label: string }[] = [
 { value: 'scheduled', label: 'Scheduled' },
 { value: 'loading', label: 'Loading' },
 { value: 'in_transit', label: 'In transit' },
 { value: 'return_pending', label: 'Truck return pending' },
 { value: 'delivered', label: 'Delivered' },
];

function statusLabel(row: DriverDelivery): string {
 return row.status_label ?? STATUS_OPTIONS.find((o) => o.value === row.status)?.label ?? row.status;
}

/**
 * "Today" / "Tomorrow" / "Overdue" beat a bare date for the one question a
 * driver asks a run sheet. Dates are server calendar dates (`YYYY-MM-DD`), so
 * they compare as strings and never drift through the browser's timezone.
 * Overdue only applies while the stop is still open — a delivered row keeps its
 * historical date without wearing a warning it no longer deserves.
 */
function scheduleLabel(row: DriverDelivery): string {
 const iso = row.scheduled_date;
 if (!iso) return 'Unscheduled';
 const today = localIsoDate();
 if (iso === today) return 'Today';
 const tomorrow = new Date();
 tomorrow.setDate(tomorrow.getDate() + 1);
 if (iso === localIsoDate(tomorrow)) return 'Tomorrow';
 const open = row.status !== 'delivered' && row.status !== 'returned' && row.status !== 'confirmed';
 if (open && iso < today) return `Overdue · ${formatDate(iso)}`;
 return formatDate(iso);
}

export default function DriverDeliveryList() {
 const navigate = useNavigate();
 // Bound to the URL: a notification or a dashboard drill-down can link straight
 // to "my deliveries for today", and Back restores the previous view.
 const [filters, setFilters] = useUrlFilters<DriverListFilters>(DEFAULT_FILTERS);

 const { data, isLoading, isError, isFetching, refetch } = useQuery({
 queryKey: ['driver', 'deliveries', filters],
 queryFn: () => driverApi.listDeliveries(filters),
 placeholderData: (prev) => prev,
 });

 const rows = data?.data ?? [];
 const currentPage = data?.meta?.current_page ?? filters.page;
 const lastPage = data?.meta?.last_page ?? 1;
 const total = data?.meta?.total ?? rows.length;
 const hasFilters = Boolean(filters.status || filters.date_from || filters.date_to);

 // The driver endpoint pages with Laravel's meta, where every field is
 // optional; the table wants the concrete shape.
 const meta: PaginationMeta | undefined = data?.meta
 ? {
 current_page: data.meta.current_page ?? 1,
 last_page: data.meta.last_page ?? 1,
 per_page: data.meta.per_page ?? filters.per_page,
 total: data.meta.total ?? rows.length,
 from: data.meta.from ?? null,
 to: data.meta.to ?? null,
 }
 : undefined;

 const columns: Column<DriverDelivery>[] = [
 {
 key: 'delivery_number',
 header: 'Delivery',
 cell: (row) => <span className="font-mono">{row.delivery_number}</span>,
 },
 {
 key: 'customer',
 header: 'Customer',
 cell: (row) => (
 <StackedCell
 primary={row.sales_order?.customer?.name ?? '—'}
 secondary={row.sales_order?.so_number ? `SO ${row.sales_order.so_number}` : undefined}
 />
 ),
 },
 {
 key: 'vehicle',
 header: 'Vehicle',
 cell: (row) => (row.vehicle?.plate_number ? <NumCell>{row.vehicle.plate_number}</NumCell> : <span className="text-muted">—</span>),
 },
 {
 key: 'scheduled',
 header: 'Scheduled',
 cell: (row) => <NumCell>{scheduleLabel(row)}</NumCell>,
 },
 {
 key: 'status',
 header: 'Status',
 cell: (row) => <Chip variant={deliveryStatusVariant[row.status]}>{statusLabel(row)}</Chip>,
 },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: STATUS_OPTIONS },
 ];

 const clearFilters = () => setFilters({ ...DEFAULT_FILTERS });

 return (
 <div>
 <PageHeader
 title="My deliveries"
 subtitle={
 total === 0
 ? 'Nothing assigned right now'
 : `${total} ${total === 1 ? 'delivery' : 'deliveries'}${hasFilters ? ' matching these filters' : ' assigned'}`
 }
 />

 <FilterBar
 filters={filterConfig}
 values={filters}
 onFilter={(key, value) =>
 setFilters((prev) => ({ ...prev, [key]: value === undefined ? '' : String(value), page: 1 }))
 }
 dateRange={{ fromKey: 'date_from', toKey: 'date_to', label: 'Scheduled' }}
 searchable={false}
 actions={
 // Trailing control, not a header action: a labelled button under the title
 // wrapped onto its own line on a phone and read as a third heading.
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuRefreshCw size={14} className={isFetching ? 'animate-spin' : undefined} />}
 aria-label="Refresh deliveries"
 disabled={isFetching}
 onClick={() => refetch()}
 />
 }
 />

 {isLoading && !data && (
 <>
 <div className="px-5 py-4 md:hidden">
 <TouchCardSkeleton count={3} label="Loading deliveries" cardClassName="h-28" />
 </div>
 <div className="hidden md:block">
 <SkeletonTable columns={5} rows={6} />
 </div>
 </>
 )}

 {isError && !isLoading && (
 <EmptyState
 icon="alert-circle"
 title="Could not load deliveries"
 description="Check your connection and try again."
 action={
 <Button variant="secondary" onClick={() => refetch()}>
 Try again
 </Button>
 }
 />
 )}

 {!isLoading && !isError && rows.length === 0 && (
 <EmptyState
 icon={hasFilters ? 'search-x' : 'truck'}
 title={hasFilters ? 'No deliveries match these filters' : 'No deliveries assigned'}
 description={
 hasFilters
 ? 'Clear the filters to see every delivery assigned to you.'
 : 'Deliveries appear here as soon as dispatch assigns them to you.'
 }
 action={
 hasFilters ? (
 <Button variant="secondary" onClick={clearFilters}>
 Clear filters
 </Button>
 ) : undefined
 }
 />
 )}

 {!isError && rows.length > 0 && (
 <>
 {/* Phone: one card per stop, thumb-sized, with the next-action status leading. */}
 <ul className="divide-y divide-default border-b border-default md:hidden">
 {rows.map((row) => (
 <li key={row.id}>
 <Link
 to={`/driver/${row.id}`}
 className={cn('flex items-start gap-3 px-5 py-4 hover:bg-elevated active:bg-subtle', focusRing)}
 >
 <div className="min-w-0 flex-1">
 <div className="flex items-center gap-2">
 <span className="font-mono tabular-nums text-sm text-primary">{row.delivery_number}</span>
 <Chip variant={deliveryStatusVariant[row.status]}>{statusLabel(row)}</Chip>
 </div>
 <div className="mt-1.5 truncate text-sm font-medium text-primary">
 {row.sales_order?.customer?.name ?? 'No customer on file'}
 </div>
 <div className="mt-0.5 truncate text-xs text-muted">
 {row.sales_order?.so_number ? `SO ${row.sales_order.so_number}` : 'No sales order'}
 {row.vehicle?.plate_number ? ` · ${row.vehicle.plate_number}` : ' · No vehicle'}
 </div>
 <div className="mt-1.5 text-xs text-secondary">{scheduleLabel(row)}</div>
 </div>
 <LuChevronRight size={16} className="mt-1 shrink-0 text-subtle" aria-hidden />
 </Link>
 </li>
 ))}
 </ul>

 {/* Desk width: the same rows in the table the rest of the ERP uses. */}
 <div className="hidden px-5 py-4 md:block">
 <DataTable
 tableKey="driver-deliveries"
 columns={columns}
 data={rows}
 meta={meta}
 onRowClick={(row) => navigate(`/driver/${row.id}`)}
 onPageChange={(page) => setFilters((prev) => ({ ...prev, page }))}
 emptyState={<span className="text-muted">No deliveries.</span>}
 />
 </div>

 {/* The table owns the pager on desktop; the card list needs its own. */}
 {lastPage > 1 && (
 <div className="flex items-center justify-between gap-3 px-5 py-3 md:hidden">
 <Button
 variant="secondary"
 size="md"
 disabled={currentPage <= 1}
 onClick={() => setFilters((prev) => ({ ...prev, page: Math.max(1, prev.page - 1) }))}
 >
 Previous
 </Button>
 <span className="font-mono tabular-nums text-xs text-muted">
 Page {currentPage} of {lastPage}
 </span>
 <Button
 variant="secondary"
 size="md"
 disabled={currentPage >= lastPage}
 onClick={() => setFilters((prev) => ({ ...prev, page: prev.page + 1 }))}
 >
 Next
 </Button>
 </div>
 )}
 </>
 )}
 </div>
 );
}
