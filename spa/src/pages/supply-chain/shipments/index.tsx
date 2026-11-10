/** Sprint 7 — Task 67 — Shipments list (inbound, imported POs). */
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LuPlus } from '@/lib/icons';
import { shipmentsApi, type ShipmentListParams } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { LinkButton } from '@/components/ui/LinkButton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { Shipment } from '@/types/supplyChain';
import { shipmentStatusVariant } from '@/lib/statusVariants';

import { useUrlFilters } from '@/hooks/useUrlFilters';
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { Input } from '@/components/ui/Input';

function localDateValue(date: Date): string {
 return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function rangeForDays(days: number, now = new Date()): { from_date: string; to_date: string } {
 const end = new Date(now.getFullYear(), now.getMonth(), now.getDate());
 const start = new Date(end);
 start.setDate(start.getDate() - days + 1);
 return { from_date: localDateValue(start), to_date: localDateValue(end) };
}

export default function ShipmentsListPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 useRealtimeList('shipment', can('supply_chain.view') || can('supply_chain.shipments.view') ? 'visible' : undefined);
 const [filters, setFilters] = useUrlFilters<ShipmentListParams>({ page: 1, per_page: 25 });
 const [fromDate, setFromDate] = useState(filters.from_date ?? '');
 const [toDate, setToDate] = useState(filters.to_date ?? '');
 useEffect(() => {
  setFromDate(filters.from_date ?? '');
  setToDate(filters.to_date ?? '');
 }, [filters.from_date, filters.to_date]);
  const queryKey = ['supply-chain', 'shipments', filters] as const;
  const { data, isLoading, isError, refetch } = useQuery({
  queryKey,
 queryFn: () => shipmentsApi.list(filters),
  placeholderData: (previous, previousQuery) =>
    sameQueryExceptPage(previousQuery?.queryKey, queryKey, 2) ? previous : undefined,
 });
 const { data: shipmentOptions } = useQuery({
 queryKey: ['supply-chain', 'shipments', 'options'],
 queryFn: () => shipmentsApi.options(),
 staleTime: 300_000,
 });
 const statusLabels = new Map((shipmentOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const customRangeValid = Boolean(fromDate && toDate && fromDate <= toDate);
 const applyRange = (range: { from_date: string; to_date: string }) => {
  setFilters((current) => ({ ...current, ...range, page: 1 }));
 };

 const columns: Column<Shipment>[] = [
 { key: 'shipment_number', header: 'Shipment',
 cell: (r) => (
 <LinkButton
 className="font-mono text-left"
 onClick={() => navigate(`/supply-chain/shipments/${r.id}`)}
 >
 {r.shipment_number}
 </LinkButton>
 ) },
 { key: 'po', header: 'PO',
 cell: (r) => r.purchase_order ? <span className="font-mono">{r.purchase_order.po_number}</span> : <span className="text-muted">—</span> },
 { key: 'carrier', header: 'Carrier', cell: (r) => r.carrier ?? '—' },
 { key: 'container', header: 'Container', cell: (r) => r.container_number ?? '—' },
 { key: 'eta', header: 'ETA', align: 'right',
 cell: (r) => <NumCell>{r.eta ?? '—'}</NumCell> },
 { key: 'status', header: 'Status',
 cell: (r) => <Chip variant={shipmentStatusVariant[r.status]}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [{ value: '', label: 'All' }, ...(shipmentOptions?.statuses ?? []), { value: 'mid_pipe', label: 'In transit, customs, or cleared' }] },
 ];

 return (
 <div>
 <PageHeader
 title="Inbound shipments"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'shipment' : 'shipments'}` : undefined}
 actions={
 can('supply_chain.shipments.manage') ? (
 <Button
 variant="primary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => navigate('/supply-chain/shipments/create')}
 >
 New shipment
 </Button>
 ) : undefined
 }
 />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search shipment, container, or B/L number…"
 />
 <fieldset className="flex flex-wrap items-end gap-2 border-b border-default px-5 py-3">
  <legend className="sr-only">Shipment date range</legend>
  <div className="flex flex-wrap items-center gap-1.5" aria-label="Date range presets">
   {[7, 30, 90].map((days) => {
    const range = rangeForDays(days);
    const selected = filters.from_date === range.from_date && filters.to_date === range.to_date;
    return <Button key={days} type="button" size="sm" variant={selected ? 'secondary' : 'ghost'} aria-pressed={selected} onClick={() => applyRange(range)}>Last {days} days</Button>;
   })}
  </div>
  <Input label="From date" type="date" value={fromDate} max={toDate || undefined} onChange={(event) => setFromDate(event.target.value)} containerClassName="w-full sm:w-40" />
  <Input label="To date" type="date" value={toDate} min={fromDate || undefined} onChange={(event) => setToDate(event.target.value)} containerClassName="w-full sm:w-40" />
  <Button type="button" size="sm" variant="secondary" disabled={!customRangeValid} onClick={() => applyRange({ from_date: fromDate, to_date: toDate })}>Apply date range</Button>
  {(filters.from_date || filters.to_date) && <Button type="button" size="sm" variant="ghost" onClick={() => setFilters((current) => { const next = { ...current, page: 1 }; delete next.from_date; delete next.to_date; return next; })}>Clear dates</Button>}
 </fieldset>
 {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load shipments"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <ListEmptyState />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 onRowClick={(r) => navigate(`/supply-chain/shipments/${r.id}`)} />
 </div>
 )}
 </div>
 );
}
