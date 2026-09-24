import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { LuChevronDown } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { CompanyName } from '@/components/brand/CompanyName';
import { formatDate } from '@/lib/formatDate';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { SupplierDeliverySummary } from '@/types/b2b';

type DeliveryFilters = { page: number; per_page: number; status?: string };

const DEFAULT_FILTERS: DeliveryFilters = { page: 1, per_page: 25 };

export default function SupplierDeliveriesPage() {
  const [filters, setFilters] = useUrlFilters<DeliveryFilters>(DEFAULT_FILTERS);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'deliveries', filters],
    queryFn: () => supplierPortalApi.listDeliveries(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<SupplierDeliverySummary>[] = [
    {
      key: 'expand',
      header: '',
      cell: (r) =>
        r.lines && r.lines.length > 0 ? (
          <button
            onClick={() => setExpandedRowId(expandedRowId === r.id ? null : r.id)}
            className="p-1 hover:bg-subtle rounded"
          >
            <LuChevronDown
              size={16}
              className={`transition-transform ${expandedRowId === r.id ? 'rotate-180' : ''}`}
            />
          </button>
        ) : null,
    },
    {
      key: 'grn_number',
      header: 'GRN #',
      cell: (r) => <span className="font-mono font-medium">{r.grn_number}</span>,
    },
    {
      key: 'purchase_order',
      header: 'PO',
      cell: (r) =>
        r.purchase_order ? (
          <span className="font-mono text-accent">{r.purchase_order.po_number}</span>
        ) : (
          '—'
        ),
    },
    {
      key: 'received_date',
      header: 'Received Date',
      cell: (r) => (
        <span className="font-mono">{r.received_date ? formatDate(r.received_date) : '—'}</span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={chipVariantForStatus(r.status)}>
          {r.status_label ?? r.status.replace(/_/g, ' ')}
        </Chip>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'pending_qc', label: 'Pending QC' },
        { value: 'accepted', label: 'Accepted' },
        { value: 'partial_accepted', label: 'Partially accepted' },
        { value: 'rejected', label: 'Rejected' },
      ],
    },
  ];

  const [expandedRowId, setExpandedRowId] = React.useState<string | null>(null);

  return (
    <div>
      <PageHeader
        title="Deliveries"
        subtitle={
          data ? (
            <>{data.meta.total} shipments you have sent to <CompanyName /></>
          ) : (
            <>Shipments you have sent to <CompanyName /></>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchable={false}
      />

      {isLoading && !data && <SkeletonTable columns={4} rows={8} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load deliveries"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && (
        <div className="px-5 py-4 space-y-4">
          {data.data.length === 0 ? (
            <EmptyState
              icon="truck"
              title="No deliveries"
              description="Goods receipts against your purchase orders will appear here."
            />
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b border-subtle">
                    {columns.map((col) => (
                      <th
                        key={col.key}
                        className="px-4 py-2 text-left font-medium text-muted text-2xs uppercase tracking-wider"
                      >
                        {col.header}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.data.map((delivery) => (
                    <React.Fragment key={delivery.id}>
                      <tr className="border-b border-subtle hover:bg-subtle transition-colors">
                        {columns.map((col) => (
                          <td key={col.key} className="px-4 py-3">
                            {col.cell(delivery)}
                          </td>
                        ))}
                      </tr>
                      {expandedRowId === delivery.id && delivery.lines && delivery.lines.length > 0 && (
                        <tr className="border-b border-subtle bg-subtle/50">
                          <td colSpan={columns.length} className="px-4 py-3">
                            <div className="space-y-3">
                              <h4 className="font-medium text-xs uppercase tracking-wider text-muted">
                                Line items
                              </h4>
                              <div className="overflow-x-auto">
                                <table className="w-full text-xs">
                                  <thead>
                                    <tr className="border-b border-default/50">
                                      <th className="px-3 py-2 text-left font-medium text-muted">Item code</th>
                                      <th className="px-3 py-2 text-left font-medium text-muted">Item name</th>
                                      <th className="px-3 py-2 text-right font-medium text-muted">Received</th>
                                      <th className="px-3 py-2 text-right font-medium text-muted">Accepted</th>
                                      <th className="px-3 py-2 text-right font-medium text-muted">Rejected</th>
                                      <th className="px-3 py-2 text-left font-medium text-muted">Remarks</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    {delivery.lines.map((line, idx) => (
                                      <tr key={idx} className="border-b border-default/30">
                                        <td className="px-3 py-2 font-mono text-muted">{line.item_code}</td>
                                        <td className="px-3 py-2">{line.item_name}</td>
                                        <td className="px-3 py-2 text-right font-mono">{line.quantity_received}</td>
                                        <td className="px-3 py-2 text-right font-mono">{line.quantity_accepted}</td>
                                        <td className="px-3 py-2 text-right font-mono">{line.quantity_rejected}</td>
                                        <td className="px-3 py-2 text-secondary text-xs">
                                          {line.remarks || '—'}
                                        </td>
                                      </tr>
                                    ))}
                                  </tbody>
                                </table>
                              </div>
                              {delivery.rejection_reason && (
                                <div className="text-xs border-t border-default/50 pt-2 mt-2">
                                  <span className="font-medium text-muted">Rejection reason: </span>
                                  <span className="text-secondary">{delivery.rejection_reason}</span>
                                </div>
                              )}
                            </div>
                          </td>
                        </tr>
                      )}
                    </React.Fragment>
                  ))}
                </tbody>
              </table>
              </div>
              <div className="flex items-center justify-between text-sm text-muted mt-4">
                <div>
                  Page {data.meta.current_page} of {data.meta.last_page} ({data.meta.total} total)
                </div>
                <div className="flex gap-2">
                  <Button
                    variant="secondary"
                    size="sm"
                    disabled={!data.links.prev}
                    onClick={() => setFilters((current) => ({ ...current, page: (current.page ?? 1) - 1 }))}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="secondary"
                    size="sm"
                    disabled={!data.links.next}
                    onClick={() => setFilters((current) => ({ ...current, page: (current.page ?? 1) + 1 }))}
                  >
                    Next
                  </Button>
                </div>
              </div>
            </>
          )}
        </div>
      )}
    </div>
  );
}
