import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { formatPeso } from '@/lib/formatNumber';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';

export default function SupplierPurchaseOrdersPage() {
  const { data: pos, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'pos'],
    queryFn: () => supplierPortalApi.listPos(),
    placeholderData: (prev) => prev,
  });

  if (isLoading) return <SkeletonBlock className="h-64 rounded-lg" />;
  if (isError) return <EmptyState icon="alert-circle" title="Failed to load purchase orders" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;

  return (
    <Panel title="Purchase Orders">
      {pos && pos.length > 0 ? (
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-border text-muted">
              <th className="text-left py-2 px-3 font-medium">PO #</th>
              <th className="text-left py-2 px-3 font-medium">Date</th>
              <th className="text-right py-2 px-3 font-medium">Amount</th>
              <th className="text-left py-2 px-3 font-medium">Expected Delivery</th>
              <th className="text-right py-2 px-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {pos.map((po) => (
              <tr key={po.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                <td className="py-2.5 px-3">
                  <Link to={`/portal/supplier/purchase-orders/${po.id}`} className="font-mono text-accent hover:underline font-medium">
                    {po.po_number}
                  </Link>
                </td>
                <td className="py-2.5 px-3 text-muted">{po.date ?? '—'}</td>
                <td className="py-2.5 px-3 text-right font-mono tabular-nums">{formatPeso(po.total_amount)}</td>
                <td className="py-2.5 px-3 text-muted">{po.expected_delivery_date ?? '—'}</td>
                <td className="py-2.5 px-3 text-right">
                  <Chip variant={chipVariantForStatus(po.status)}>{po.status.replace(/_/g, ' ')}</Chip>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <EmptyState icon="file-text" title="No purchase orders" description="Purchase orders from your customers will appear here." />
      )}
    </Panel>
  );
}
