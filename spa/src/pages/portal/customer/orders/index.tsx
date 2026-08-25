import { useState } from 'react';
import { PortalTable } from '@/components/portal/PortalTable';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { Select } from '@/components/ui/Select';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { CompanyName } from '@/components/brand/CompanyName';

const STATUS_OPTIONS = [
  { value: 'draft', label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'in_production', label: 'In production' },
  { value: 'partially_delivered', label: 'Partially delivered' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'cancelled', label: 'Cancelled' },
];

export default function CustomerOrdersPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const {
    data: ordersPage,
    isLoading,
    isError,
    refetch,
  } = useQuery({
    queryKey: ['portal', 'customer', 'orders', { page, status }],
    queryFn: () => customerPortalApi.listOrders({
      page,
      status: status || undefined,
    }),
    placeholderData: (prev) => prev,
  });
  const orders = ordersPage?.data ?? [];

  return (
    <div>
      <PageHeader
        title="My Orders"
        subtitle={
          <>
            Sales orders placed with <CompanyName />
          </>
        }
      />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 max-w-5xl">
        {isLoading && <SkeletonBlock className="h-64 rounded-md" />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load orders"
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {!isLoading && !isError && (
          <Panel noPadding>
            <div className="border-b border-default px-4 py-3">
              <Select
                label="Status"
                value={status}
                onChange={(event) => {
                  setStatus(event.target.value);
                  setPage(1);
                }}
                containerClassName="w-64"
              >
                <option value="">All statuses</option>
                {STATUS_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </Select>
            </div>

            {orders.length > 0 ? (
              <>
                <PortalTable>
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
                        <tr key={order.id} className={trCls}>
                          <Td>
                            <Link
                              to={'/portal/customer/orders/' + order.id}
                              className="font-mono text-accent hover:underline font-medium"
                            >
                              {order.so_number}
                            </Link>
                          </Td>
                          <Td className="text-muted">{order.date ?? '—'}</Td>
                          <Td align="right" mono>
                            {formatPeso(order.total_amount)}
                          </Td>
                          <Td align="right" mono>
                            <Chip variant={chipVariantForStatus(order.status)}>
                              {order.status_label ?? order.status.replace(/_/g, ' ')}
                            </Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </PortalTable>
                {ordersPage?.meta && (
                  <div className="px-4 pb-4">
                    <DataTablePagination meta={ordersPage.meta} onPageChange={setPage} />
                  </div>
                )}
              </>
            ) : (
              <EmptyState
                icon="package"
                title="No orders"
                description="Your sales orders will appear here once placed."
              />
            )}
          </Panel>
        )}
      </div>
    </div>
  );
}
