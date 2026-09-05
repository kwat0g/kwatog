import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LuArrowRight } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import {
  DashboardShell,
  KpiGrid,
  PanelRow,
} from '@/components/dashboard/DashboardShell';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerDashboardPage() {
  const dashboard = useQuery({
    queryKey: ['portal', 'customer', 'dashboard'],
    queryFn: () => customerPortalApi.dashboard(),
  });

  const viewAll = (to: string) => (
    <Link to={to} className="text-2xs text-accent hover:underline flex items-center gap-1">
      View all <LuArrowRight size={11} />
    </Link>
  );

  return (
    <DashboardShell
      title="Dashboard"
      subtitle="Orders, deliveries, and account balance at a glance"
      query={dashboard}
      kpiCount={4}
    >
      {(data) => (
        <>
          <KpiGrid count={4}>
            <StatCard
              label="Open Orders"
              value={data.open_so_count}
              helper="Pending fulfillment"
              linkTo="/portal/customer/orders"
            />
            <StatCard
              label="Pending Deliveries"
              value={data.pending_delivery_count}
              helper="Awaited deliveries"
              linkTo="/portal/customer/deliveries"
            />
            <StatCard
              label="Open Invoices"
              value={data.open_invoice_count}
              helper="Invoices due"
              linkTo="/portal/customer/invoices"
            />
            <StatCard
              label="Outstanding"
              value={formatPeso(data.total_outstanding)}
              helper="Total balance"
              linkTo="/portal/customer/statement-of-account"
            />
          </KpiGrid>

          <PanelRow>
            <Panel title="Recent Orders" actions={viewAll('/portal/customer/orders')} noPadding>
              {data.recent_orders.length > 0 ? (
                <div className="overflow-x-auto">
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
                      {data.recent_orders.map((order) => (
                        <tr key={order.id} className={trCls}>
                          <Td>
                            <Link
                              to={`/portal/customer/orders/${order.id}`}
                              className="font-mono font-medium text-accent hover:underline"
                            >
                              {order.so_number}
                            </Link>
                          </Td>
                          <Td className="text-muted">{order.date ? formatDate(order.date) : '—'}</Td>
                          <Td align="right" mono>{formatPeso(order.total_amount)}</Td>
                          <Td align="right" mono>
                            <Chip variant={chipVariantForStatus(order.status)}>
                              {order.status_label ?? order.status.replace(/_/g, ' ')}
                            </Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState size="compact" icon="package" title="No orders yet" />
              )}
            </Panel>

            <Panel title="Recent Invoices" actions={viewAll('/portal/customer/invoices')} noPadding>
              {data.recent_invoices.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Invoice #</Th>
                        <Th>Date</Th>
                        <Th align="right">Amount</Th>
                        <Th align="right">Status</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_invoices.map((inv) => (
                        <tr key={inv.id} className={trCls}>
                          <Td>
                            <Link
                              to={`/portal/customer/invoices/${inv.id}`}
                              className="font-mono font-medium text-accent hover:underline"
                            >
                              {inv.invoice_number}
                            </Link>
                          </Td>
                          <Td className="text-muted">{inv.date ? formatDate(inv.date) : '—'}</Td>
                          <Td align="right" mono>{formatPeso(inv.total_amount)}</Td>
                          <Td align="right" mono>
                            <Chip variant={chipVariantForStatus(inv.status)}>
                              {inv.status_label ?? inv.status.replace(/_/g, ' ')}
                            </Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState size="compact" icon="receipt" title="No invoices yet" />
              )}
            </Panel>
          </PanelRow>

          <PanelRow>
            <Panel title="Recent Deliveries" actions={viewAll('/portal/customer/deliveries')} noPadding>
              {data.recent_deliveries.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Delivery #</Th>
                        <Th>Date</Th>
                        <Th align="right">Status</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_deliveries.map((delivery) => (
                        <tr key={delivery.id} className={trCls}>
                          <Td>
                            <Link
                              to={`/portal/customer/deliveries/${delivery.id}`}
                              className="font-mono font-medium text-accent hover:underline"
                            >
                              {delivery.delivery_number}
                            </Link>
                          </Td>
                          <Td className="text-muted">
                            {formatDate(delivery.delivered_at ?? delivery.scheduled_date)}
                          </Td>
                          <Td align="right" mono>
                            <Chip variant={chipVariantForStatus(delivery.status)}>
                              {delivery.status_label ?? delivery.status.replace(/_/g, ' ')}
                            </Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState size="compact" icon="truck" title="No deliveries yet" />
              )}
            </Panel>

            <Panel title="Recent Quality Complaints" actions={viewAll('/portal/customer/complaints')} noPadding>
              {data.recent_complaints.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>Complaint #</Th>
                        <Th>Description</Th>
                        <Th align="right">Status</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_complaints.map((complaint) => (
                        <tr key={complaint.id} className={trCls}>
                          <Td mono className="text-muted">{complaint.complaint_number}</Td>
                          <Td className="max-w-xs truncate">{complaint.description}</Td>
                          <Td align="right" mono>
                            <Chip variant={chipVariantForStatus(complaint.status)}>
                              {complaint.status_label ?? complaint.status.replace(/_/g, ' ')}
                            </Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState size="compact" icon="message-square" title="No complaints yet" />
              )}
            </Panel>
          </PanelRow>
        </>
      )}
    </DashboardShell>
  );
}
