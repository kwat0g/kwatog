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
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function SupplierPurchaseOrdersPage() {
  const { data: pos, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'pos'],
    queryFn: () => supplierPortalApi.listPos(),
    placeholderData: (prev) => prev,
  });

  return (
    <div>
      <PageHeader title="Purchase Orders" subtitle="Orders issued to you by Ogami" />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load purchase orders"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
            {pos && pos.length > 0 ? (
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
                    <tr key={po.id} className={trCls}>
                      <Td>
                        <Link to={`/portal/supplier/purchase-orders/${po.id}`} className="font-mono text-accent hover:underline font-medium">
                          {po.po_number}
                        </Link>
                      </Td>
                      <Td className="text-muted">{po.date ?? '—'}</Td>
                      <Td align="right" mono>{formatPeso(po.total_amount)}</Td>
                      <Td className="text-muted">{po.expected_delivery_date ?? '—'}</Td>
                      <Td align="right" mono>
                        <Chip variant={chipVariantForStatus(po.status)}>{po.status.replace(/_/g, ' ')}</Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <EmptyState icon="file-text" title="No purchase orders" description="Purchase orders from your customers will appear here." />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
