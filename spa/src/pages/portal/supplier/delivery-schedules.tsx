import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { useState } from 'react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { formatDate } from '@/lib/formatDate';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function SupplierDeliverySchedulesPage() {
  const [searchParams] = useSearchParams();
  const purchaseOrderId = searchParams.get('purchase_order_id') ?? undefined;
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const schedules = useQuery({
    queryKey: ['portal', 'supplier', 'delivery-schedules', purchaseOrderId, page, perPage],
    queryFn: () => supplierPortalApi.listDeliverySchedules({ page, per_page: perPage, purchase_order_id: purchaseOrderId }),
    placeholderData: (previous) => previous,
  });

  return (
    <div>
      <PageHeader
        title="Past delivery plans"
        subtitle="Read-only history. Report actual dispatch from the purchase order."
        backTo="/portal/supplier/purchase-orders"
        backLabel="Purchase orders"
      />
      <div className="px-5 py-4 space-y-4">
        {schedules.isLoading && !schedules.data && <SkeletonTable columns={4} rows={6} />}
        {schedules.isError && (
          <EmptyState icon="alert-circle" title="Could not load past delivery plans" action={<Button variant="secondary" onClick={() => schedules.refetch()}>Retry</Button>} />
        )}
        {schedules.isSuccess && schedules.data.data.length === 0 && (
          <EmptyState icon="clipboard-list" title="No past delivery plans" description="Use Add shipment on the purchase order when goods leave." />
        )}
        {schedules.data?.data.map((schedule) => (
          <Panel
            key={schedule.id}
            title={schedule.planned_delivery_date ? formatDate(schedule.planned_delivery_date) : `${schedule.month} · Month-only plan`}
            meta={<span className="flex items-center gap-2">
              {schedule.purchase_order?.po_number && <span className="font-mono text-accent">{schedule.purchase_order.po_number}</span>}
              <Chip variant={chipVariantForStatus(schedule.status)}>{schedule.status_label ?? schedule.status}</Chip>
              <span className="text-sm">Submitted {formatDate(schedule.created_at)}</span>
            </span>}
            noPadding
          >
            <div className="overflow-x-auto">
              <table className={tableCls}>
                <thead><tr className={theadTrCls}><Th>Product</Th><Th align="right">Qty</Th><Th>Notes</Th></tr></thead>
                <tbody>{schedule.lines.map((line, index) => (
                  <tr key={index} className={trCls}><Td>{line.product_name}</Td><Td align="right" mono>{line.quantity}</Td><Td>{line.notes ?? '—'}</Td></tr>
                ))}</tbody>
              </table>
            </div>
          </Panel>
        ))}
        {schedules.data && schedules.data.data.length > 0 && (
          <DataTablePagination
            meta={schedules.data.meta}
            perPage={perPage}
            onPageChange={setPage}
            onPageSizeChange={(size) => { setPerPage(size); setPage(1); }}
          />
        )}
      </div>
    </div>
  );
}
