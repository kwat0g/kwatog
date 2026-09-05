import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LuArrowRight } from '@/lib/icons';
import { supplierPortalApi } from '@/api/b2b/supplier';
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

export default function SupplierDashboardPage() {
  const dashboard = useQuery({
    queryKey: ['portal', 'supplier', 'dashboard'],
    queryFn: () => supplierPortalApi.dashboard(),
  });

  const viewAll = (to: string) => (
    <Link to={to} className="text-2xs text-accent hover:underline flex items-center gap-1">
      View all <LuArrowRight size={11} />
    </Link>
  );

  return (
    <DashboardShell
      title="Dashboard"
      subtitle="Purchase orders, deliveries, and payment status at a glance"
      query={dashboard}
      kpiCount={4}
    >
      {(data) => (
        <>
          <KpiGrid count={4}>
            <StatCard
              label="Open POs"
              value={data.open_po_count}
              helper="Pending fulfillment"
              linkTo="/portal/supplier/purchase-orders"
            />
            <StatCard
              label="Pending Deliveries"
              value={data.pending_delivery_count}
              helper="Awaited deliveries"
              linkTo="/portal/supplier/deliveries"
            />
            <StatCard
              label="Unpaid Invoices"
              value={data.unpaid_invoice_count}
              helper="Invoices due"
              linkTo="/portal/supplier/invoices"
            />
            <StatCard
              label="Total Unpaid"
              value={formatPeso(data.total_unpaid_amount)}
              helper="Outstanding balance"
              linkTo="/portal/supplier/statement-of-account"
            />
          </KpiGrid>

          <PanelRow>
            <Panel title="Recent Purchase Orders" actions={viewAll('/portal/supplier/purchase-orders')} noPadding>
              {data.recent_pos.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className={tableCls}>
                    <thead>
                      <tr className={theadTrCls}>
                        <Th>PO #</Th>
                        <Th>Date</Th>
                        <Th align="right">Amount</Th>
                        <Th align="right">Status</Th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_pos.map((po) => (
                        <tr key={po.id} className={trCls}>
                          <Td>
                            <Link
                              to={`/portal/supplier/purchase-orders/${po.id}`}
                              className="font-mono font-medium text-accent hover:underline"
                            >
                              {po.po_number}
                            </Link>
                          </Td>
                          <Td className="text-muted">{po.date ? formatDate(po.date) : '—'}</Td>
                          <Td align="right" mono>{formatPeso(po.total_amount)}</Td>
                          <Td align="right" mono>
                            <Chip variant={chipVariantForStatus(po.status)}>
                              {po.status_label ?? po.status.replace(/_/g, ' ')}
                            </Chip>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <EmptyState size="compact" icon="file-text" title="No purchase orders yet" />
              )}
            </Panel>

            <Panel title="Recent Invoices" actions={viewAll('/portal/supplier/invoices')} noPadding>
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
                              to={`/portal/supplier/invoices/${inv.id}`}
                              className="font-mono font-medium text-accent hover:underline"
                            >
                              {inv.bill_number}
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
        </>
      )}
    </DashboardShell>
  );
}
