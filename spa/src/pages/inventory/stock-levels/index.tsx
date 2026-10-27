import { useQuery } from '@tanstack/react-query';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { stockLevelsApi } from '@/api/inventory/stock';
import { itemsApi } from '@/api/inventory/items';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { SegmentedControl } from '@/components/ui/SegmentedControl';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatPeso, formatQuantity, formatUnitCost } from '@/lib/formatNumber';
import { StockMovementsTab } from '@/pages/inventory/movements';
import type { StockLevel } from '@/types/inventory';
import { ReservationsTab } from './ReservationsTab';

export default function StockLevelsPage() {
  const navigate = useNavigate();
  const [search] = useSearchParams();
  const itemFilter = search.get('item_id') ?? '';
  const typeFilter = search.get('type') ?? '';
  const movementFilter = search.get('movement_id') ?? '';
  // Scope cut 2026-08-08: Stock Movements folded into this page as a view toggle.
  // Deep links (item detail "View movements", stock-adjustment back) land on the
  // movements tab via ?view=movements; ?type=transfer filters the movement list.
  const view =
    search.get('view') === 'movements'
      ? 'movements'
      : search.get('view') === 'reservations'
        ? 'reservations'
        : 'levels';
  const changeView = (next: string) => {
    const params = new URLSearchParams(search.toString());
    if (next !== 'levels') params.set('view', next);
    else params.delete('view');
    navigate(`?${params.toString()}`, { replace: true });
  };
  const [filters, setFilters] = useUrlFilters<Record<string, unknown>>({ page: 1, per_page: 50 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'stock-levels', filters],
    queryFn: () => stockLevelsApi.list(filters),
    enabled: view === 'levels',
    placeholderData: (prev) => prev,
  });
  const { data: itemOptions } = useQuery({
    queryKey: ['inventory', 'items', 'options'],
    queryFn: itemsApi.options,
    staleTime: 5 * 60 * 1000,
  });

  const columns: Column<StockLevel>[] = [
    {
      key: 'item',
      header: 'Item',
      width: 270,
      minWidth: 220,
      maxWidth: 420,
      cell: (r) => (
        <div>
          <span className="block truncate font-mono" title={r.item?.code}>{r.item?.code}</span>
          <div className="truncate text-xs text-muted" title={r.item?.name}>{r.item?.name}</div>
        </div>
      ),
    },
    {
      key: 'loc',
      header: 'Location',
      width: 180,
      minWidth: 160,
      maxWidth: 260,
      cell: (r) => <span className="block truncate font-mono" title={r.location?.full_code}>{r.location?.full_code}</span>,
    },
    {
      key: 'qty',
      header: 'Quantity',
      width: 180,
      minWidth: 170,
      maxWidth: 220,
      align: 'right',
      cell: (r) => (
        <NumCell className="whitespace-nowrap">
          {formatQuantity(r.quantity)} {r.item?.unit_of_measure}
        </NumCell>
      ),
    },
    {
      key: 'res',
      header: 'Reserved',
      width: 152,
      minWidth: 144,
      maxWidth: 192,
      align: 'right',
      cell: (r) => <NumCell>{formatQuantity(r.reserved_quantity)}</NumCell>,
    },
    {
      key: 'avail',
      header: 'Usable now',
      width: 190,
      minWidth: 176,
      maxWidth: 250,
      align: 'right',
      cell: (r) => (
        <div>
          <NumCell>
            {r.usable_quantity === undefined ? '—' : formatQuantity(r.usable_quantity)}
          </NumCell>
          {Number(r.expired_quantity ?? 0) > 0 && (
            <div className="text-xs text-danger-fg">
              {formatQuantity(r.expired_quantity)} expired
            </div>
          )}
          {Number(r.quality_held_quantity ?? 0) > 0 && (
            <div className="text-xs text-warning-fg">
              {formatQuantity(r.quality_held_quantity)} QC held
            </div>
          )}
        </div>
      ),
    },
    {
      key: 'wac',
      header: 'WAC',
      width: 160,
      minWidth: 152,
      maxWidth: 200,
      align: 'right',
      cell: (r) => <NumCell>{formatUnitCost(r.weighted_avg_cost)}</NumCell>,
    },
    {
      key: 'val',
      header: 'Total value',
      width: 250,
      minWidth: 232,
      maxWidth: 300,
      align: 'right',
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_value)}</NumCell>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'item_type',
      label: 'Type',
      type: 'select',
      options: [{ value: '', label: 'All' }, ...(itemOptions?.item_types ?? [])],
    },
  ];

  return (
    <PageShell viewport className="flex flex-col gap-3">
      <PageHeader
        inset={false}
        title={
          view === 'levels'
            ? 'Stock levels'
            : view === 'reservations'
              ? 'Material reservations'
              : 'Stock movements'
        }
        subtitle={view === 'levels' ? (data ? `${data.meta.total} entries` : undefined) : undefined}
        refreshingQueryKey={
          view === 'movements'
            ? ['inventory', 'movements']
            : view === 'reservations'
              ? ['inventory', 'reservations']
              : ['inventory', 'stock-levels']
        }
        actions={
          <>
            <SegmentedControl
              label="Stock view"
              value={view}
              onChange={changeView}
              options={[
                { value: 'levels', label: 'Stock levels' },
                { value: 'movements', label: 'Movements' },
                { value: 'reservations', label: 'Reservations' },
              ]}
            />
          </>
        }
      />
      {view === 'movements' ? (
        <StockMovementsTab
          initialItemId={itemFilter || undefined}
          initialMovementType={typeFilter || undefined}
          initialMovementId={movementFilter || undefined}
        />
      ) : view === 'reservations' ? (
        <ReservationsTab />
      ) : (
        <>
          <p className="text-xs text-muted">
            Usable stock excludes reservations, Quality holds, expired lots and blocked locations.
            Quantity shows all physical stock, including material awaiting review.
          </p>
          <FilterBar
            inset={false}
            filters={filterConfig}
            values={filters}
            onSearch={(s) => setFilters((f) => ({ ...f, search: s, page: 1 }))}
            onFilter={(k, v) => setFilters((f) => ({ ...f, [k]: v, page: 1 }))}
            searchPlaceholder="Search item…"
          />
          {isLoading && !data && <div className="min-h-0 flex-1 overflow-auto"><SkeletonTable columns={7} rows={8} /></div>}
          {isError && (
            <div className="min-h-0 flex-1 flex items-center justify-center">
              <EmptyState
                icon="alert-circle"
                title="Failed to load stock"
                action={<Button onClick={() => refetch()}>Retry</Button>}
              />
            </div>
          )}
          {data && data.data.length === 0 && (
            <div className="min-h-0 flex-1 flex items-center justify-center">
              <EmptyState
                icon="inbox"
                title="No matching stock"
                description="Check the item search or clear the filters."
              />
            </div>
          )}
          {data && data.data.length > 0 && (
            <div className="min-h-0 flex-1 flex flex-col">
              <DataTable
                tableKey="inventory-stock-levels"
                fillHeight
                fixedColumnLayout
                onRowClick={(r) => navigate(`/inventory/items/${r.item?.id}`)}
                columns={columns}
                data={data.data}
                meta={data.meta}
                onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
                onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
              />
            </div>
          )}
        </>
      )}
    </PageShell>
  );
}
