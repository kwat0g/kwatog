import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { stockLevelsApi } from '@/api/inventory/stock';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import { formatQuantity } from '@/lib/formatNumber';
import type { MaterialReservation, WorkOrderReservations } from '@/types/inventory';
import type { ListParams } from '@/types';

export function ReservationsTab() {
  const { can } = usePermission();
  const [filters, setFilters] = useUrlFilters<ListParams>({
    page: 1,
    per_page: 50,
    status: 'reserved',
    group_by: 'work_order',
  });
  const query = useQuery({
    queryKey: ['inventory', 'reservations', filters],
    queryFn: () => stockLevelsApi.reservations(filters),
  });
  const statusChip = (status: MaterialReservation['status'], count?: number) => (
    <Chip
      key={status}
      variant={status === 'reserved' ? 'info' : status === 'issued' ? 'success' : 'neutral'}
    >
      {count !== undefined ? `${count} ` : ''}
      {status === 'reserved' ? 'Reserved' : status === 'issued' ? 'Issued' : 'Released'}
    </Chip>
  );
  const columns: Column<WorkOrderReservations>[] = [
    {
      key: 'wo',
      header: 'Work order',
      className: 'min-w-[180px] whitespace-nowrap',
      cell: (row) =>
        row.work_order ? (
          can('production.view') && can('production.work_orders.view') ? (
            <Link
              className="inline-flex min-h-11 items-center text-link font-mono"
              to={`/production/work-orders/${row.work_order.id}`}
            >
              {row.work_order.wo_number}
            </Link>
          ) : (
            <span className="font-mono">{row.work_order.wo_number}</span>
          )
        ) : (
          'Unlinked'
        ),
    },
    {
      key: 'materials',
      header: 'Materials',
      className: 'whitespace-nowrap',
      cell: (row) => {
        const count = new Set(row.materials.map((line) => line.item?.id ?? line.id)).size;
        return `${count} ${count === 1 ? 'material' : 'materials'}`;
      },
    },
    {
      key: 'status',
      header: 'Status',
      className: 'min-w-[160px]',
      cell: (row) => (
        <div className="flex flex-wrap gap-1 py-1">
          {(['reserved', 'issued', 'released'] as const).map((status) => {
            const count = row.materials.filter((line) => line.status === status).length;
            return count > 0 ? statusChip(status, count) : null;
          })}
        </div>
      ),
    },
    {
      key: 'reserved_at',
      header: 'Latest reservation',
      className: 'whitespace-nowrap',
      cell: (row) => {
        const dates = row.materials.flatMap((line) => (line.reserved_at ? [line.reserved_at] : []));
        return dates.length
          ? formatDateTime(
              dates.reduce((latest, date) =>
                Date.parse(date) > Date.parse(latest) ? date : latest,
              ),
            )
          : '—';
      },
    },
  ];
  return (
    <div className="min-h-0 flex flex-1 flex-col gap-3">
      <p className="text-xs text-muted">
        One row per work order. Expand to see matching materials and locations. Reserved quantities
        remain allocated; issued and released lines show history. Physical handover is recorded in
        Movements.
      </p>
      <FilterBar
        inset={false}
        values={filters}
        onSearch={(search) => setFilters((previous) => ({ ...previous, search, page: 1 }))}
        onFilter={(key, value) =>
          setFilters((previous) => ({ ...previous, [key]: value, page: 1 }))
        }
        searchPlaceholder="Search WO number or material…"
        filters={[
          {
            key: 'status',
            label: 'Status',
            type: 'select',
            options: [
              { value: '', label: 'All statuses' },
              { value: 'reserved', label: 'Reserved' },
              { value: 'issued', label: 'Issued' },
              { value: 'released', label: 'Released' },
            ],
          },
        ]}
      />
      {query.isLoading && <div className="min-h-0 flex-1 overflow-auto"><SkeletonTable columns={4} rows={6} /></div>}
      {query.isError && (
        <div className="min-h-0 flex-1 flex items-center justify-center">
          <EmptyState
            icon="alert-circle"
            title="Reservations could not be loaded"
            action={<Button onClick={() => query.refetch()}>Retry</Button>}
          />
        </div>
      )}
      {!query.isError && query.data?.data.length === 0 && (
        <div className="min-h-0 flex-1 flex items-center justify-center">
          <EmptyState
            icon="inbox"
            title="No matching reservations"
            description="Try another WO or material, or change the status filter."
          />
        </div>
      )}
      {!query.isError && query.data && query.data.data.length > 0 && (
        <div className="min-h-0 flex-1 flex flex-col">
          <DataTable
            tableKey="stock-level-reservations"
            fillHeight
            key={`${filters.search ?? ''}:${filters.status ?? ''}:${filters.page}:${filters.per_page}`}
            columns={columns}
            data={query.data.data}
            meta={query.data.meta}
            expandOnRowClick
            renderExpanded={(row) => (
              <table className="w-full min-w-160 text-sm">
                <caption className="pb-2 text-left font-medium text-primary">
                  Materials for {row.work_order?.wo_number ?? 'unlinked reservations'}
                </caption>
                <thead>
                  <tr className="border-b border-default text-muted">
                    {['Material', 'Location', 'Quantity', 'Status', 'Reserved at'].map((label) => (
                      <th
                        key={label}
                        scope="col"
                        className={`px-2 py-2 font-medium ${label === 'Quantity' ? 'text-right' : 'text-left'}`}
                      >
                        {label}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {row.materials.map((line) => (
                    <tr key={line.id} className="border-b border-subtle last:border-0">
                      <td className="px-2 py-2">
                        <span className="font-mono">{line.item?.code ?? 'Unknown item'}</span>
                        <p className="text-muted">{line.item?.name}</p>
                      </td>
                      <td className="px-2 py-2">{line.location?.full_code ?? 'No location'}</td>
                      <td className="px-2 py-2 text-right whitespace-nowrap">
                        <NumCell>
                          {formatQuantity(line.quantity)} {line.item?.unit_of_measure}
                        </NumCell>
                      </td>
                      <td className="px-2 py-2">{statusChip(line.status)}</td>
                      <td className="px-2 py-2">{formatDateTime(line.reserved_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
            onPageChange={(page) => setFilters((previous) => ({ ...previous, page }))}
            onPageSizeChange={(per_page) =>
              setFilters((previous) => ({ ...previous, per_page, page: 1 }))
            }
          />
        </div>
      )}
    </div>
  );
}
