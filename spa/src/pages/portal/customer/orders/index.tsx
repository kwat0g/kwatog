import { cn } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { CompanyName } from '@/components/brand/CompanyName';

export default function CustomerOrdersPage() {
  const navigate = useNavigate();
  const { data: orders, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'orders'],
    queryFn: () => customerPortalApi.listOrders(),
    placeholderData: (prev) => prev });

  return (
    <div>
      <PageHeader title="My Orders" subtitle={<>Sales orders placed with <CompanyName /></>} />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load orders"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
            {orders && orders.length > 0 ? (
              <table className={tableCls}>
                <thead>
                  <tr className={theadTrCls}>
                    <Th>Order #</Th>
                    <Th>Date</Th>
                    <Th align="right">Amount</Th>
                    <Th align="right">Status</Th>
                  </tr>
                </thead>
                <tbody>
                  {orders.map((order) => (
                    <tr key={order.id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate(`/portal/customer/orders/${order.id}`)}>
                      <Td>
                        
                          {order.so_number}
                        
                      </Td>
                      <Td className="text-muted">{order.date ?? '—'}</Td>
                      <Td align="right" mono>{formatPeso(order.total_amount)}</Td>
                      <Td align="right" mono>
                        <Chip variant={chipVariantForStatus(order.status)}>{order.status_label ?? order.status.replace(/_/g, ' ')}</Chip>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : (
              <EmptyState icon="package" title="No orders" description="Your sales orders will appear here once placed." />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
