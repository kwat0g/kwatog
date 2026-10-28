import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LuArrowRight } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Chip } from '@/components/ui/Chip';
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
import { complaintStatusVariant, deliveryStatusVariant, invoiceStatusVariant, salesOrderStatusVariant } from '@/lib/statusVariants';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerDashboardPage() {
  const dashboard = useQuery({
    queryKey: ['portal', 'customer', 'dashboard'],
    queryFn: () => customerPortalApi.dashboard(),
  });
  const account = useQuery({
    queryKey: ['portal', 'customer', 'me'],
    queryFn: () => customerPortalApi.me(),
  });
  const terms = account.data?.customer_terms;
  const warranty = terms?.warranty_defaults;

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
              linkTo="/portal/customer/invoices?signal=unpaid"
            />
            <StatCard
              label="Outstanding"
              value={formatPeso(data.total_outstanding)}
              helper="Total balance"
              linkTo="/portal/customer/statement-of-account"
            />
          </KpiGrid>

          <Panel title="Terms for your account">
            {account.isLoading ? <p className="text-sm text-muted">Loading your terms…</p>
              : account.isError ? <p role="alert" className="text-sm text-danger-fg">Account terms could not be loaded.</p>
                : terms ? <div className="space-y-4">
                  <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
                    <div><dt className="text-xs text-muted">Agreement status</dt><dd className="mt-0.5 capitalize">{terms.agreement_status.replaceAll('_', ' ')}</dd></div>
                    <div><dt className="text-xs text-muted">Agreement period</dt><dd className="mt-0.5">{terms.agreement_starts_on || terms.agreement_ends_on ? `${formatDate(terms.agreement_starts_on)} – ${formatDate(terms.agreement_ends_on)}` : 'Not recorded'}</dd></div>
                    <div><dt className="text-xs text-muted">Payment terms</dt><dd className="mt-0.5">{terms.payment_terms_days} days</dd></div>
                    <div><dt className="text-xs text-muted">Allowed payment methods</dt><dd className="mt-0.5">{terms.allowed_payment_methods === null ? 'All supported methods' : terms.allowed_payment_methods.length ? terms.allowed_payment_methods.map((method) => ({ cash: 'Cash', check: 'Check', bank_transfer: 'Bank transfer' })[method]).join(', ') : 'None configured'}</dd></div>
                    <div><dt className="text-xs text-muted">Shipping paid by</dt><dd className="mt-0.5">{terms.shipping_payer ? terms.shipping_payer === 'company' ? 'Company' : 'Customer' : 'Not specified'}</dd></div>
                    <div><dt className="text-xs text-muted">Warranty duration</dt><dd className="mt-0.5">{warranty?.warranty_duration_days != null ? `${warranty.warranty_duration_days} days` : 'Not specified'}</dd></div>
                    <div><dt className="text-xs text-muted">Report deadline (from delivery)</dt><dd className="mt-0.5">{warranty?.warranty_report_window_days != null ? `${warranty.warranty_report_window_days} days` : 'Not specified'}</dd></div>
                    <div><dt className="text-xs text-muted">Warranty starts from</dt><dd className="mt-0.5">{warranty?.warranty_start_basis ? warranty.warranty_start_basis[0].toUpperCase() + warranty.warranty_start_basis.slice(1) : 'Not specified'}</dd></div>
                    <div><dt className="text-xs text-muted">Covered issues</dt><dd className="mt-0.5">{warranty?.warranty_covered_issues?.length ? warranty.warranty_covered_issues.map((issue) => ({ manufacturing_defect: 'Manufacturing defect', shipping_damage: 'Shipping damage', customer_caused_damage: 'Customer-caused damage' })[issue]).join(', ') : 'Not specified'}</dd></div>
                    <div><dt className="text-xs text-muted">Possible remedies</dt><dd className="mt-0.5">{warranty?.warranty_remedies?.length ? warranty.warranty_remedies.map((remedy) => ({ repair: 'Repair', replace: 'Replace', credit: 'Credit', refund: 'Refund' })[remedy]).join(', ') : 'Not specified'}</dd></div>
                  </dl>
                  <p className="border-t border-subtle pt-3 text-xs text-muted">Order totals exclude freight. Sales confirms any customer-paid freight separately. Warranty reports are reviewed by our team under these terms.</p>
                </div>
                  : <p className="text-sm text-muted">No account terms are recorded. Contact Sales for details.</p>}
          </Panel>

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
                            <Chip variant={salesOrderStatusVariant[order.status]}>
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
                            <Chip variant={invoiceStatusVariant[inv.status] ?? 'neutral'}>
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
                            <Chip variant={deliveryStatusVariant[delivery.status]}>
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
                            <Chip variant={complaintStatusVariant[complaint.status]}>
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
