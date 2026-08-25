import { PortalTable } from '@/components/portal/PortalTable';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { CompanyName } from '@/components/brand/CompanyName';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { PortalPoSummary } from '@/types/b2b';

type PurchaseOrderFilters = { page: number; per_page: number; status?: string; search?: string };

export default function SupplierPurchaseOrdersPage() {
  const [filters, setFilters] = useUrlFilters<PurchaseOrderFilters>({ page: 1, per_page: 25 });
  const {
    data,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'supplier', 'pos', filters],
    queryFn: () => supplierPortalApi.listPos(filters),
    placeholderData: (prev) => prev,
  });

  const pos: PortalPoSummary[] = data?.data ?? [];
  const filterConfig: FilterConfig[] = [{
    key: 'status',
    label: 'Status',
    type: 'select',
    options: [
      { value: '', label: 'All' },
      { value: 'approved', label: 'Approved' },
      { value: 'sent', label: 'Sent' },
      { value: 'partially_received', label: 'Partially received' },
      { value: 'received', label: 'Received' },
      { value: 'closed', label: 'Closed' },
    ],
  }];

  return (
    <div>
      <PageHeader
        title="Purchase Orders"
        subtitle={
          <>
            Orders issued to you by <CompanyName />
          </>
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((current) => ({ ...current, search: search || undefined, page: 1 }))}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchPlaceholder="Search PO number…"
      />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load purchase orders"
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
            {pos.length > 0 ? (
              <PortalTable>
<table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>PO #</Th>
                    <Th>Date</Th>
                    <Th align="right">Amount</Th>
                    <Th>Expected Delivery</Th>
                    <Th align="right">Status</Th>
                  </tr>
                </thead>
                <tbody>
                  {pos.map((po) => (
                    <tr
                      key={po.id}
                      className={trCls}
                    >
                      <Td>
                        <Link
                          to={`/portal/supplier/purchase-orders/${po.id}`}
                          className="font-mono text-accent hover:underline font-medium"
                        >
                          {po.po_number}
                        </Link>
                      </Td>
                      <Td className="text-muted">{po.date ?? '—'}</Td>
                      <Td align="right" mono>
                        {formatPeso(po.total_amount)}
                      </Td>
                      <Td className="text-muted">{po.expected_delivery_date ?? '—'}</Td>
                      <Td align="right" mono>
                        <Chip variant={chipVariantForStatus(po.status)}>
                          {po.status_label ?? po.status.replace(/_/g, ' ')}
                        </Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
</PortalTable>
            ) : (
              <EmptyState
                icon="file-text"
                title="No purchase orders"
                description="Purchase orders from your customers will appear here."
              />
            )}
          </Panel>
        )}
        {data && <DataTablePagination
          meta={data.meta}
          perPage={filters.per_page}
          onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
          onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
        />}
      </div>
    </div>
  );
}
