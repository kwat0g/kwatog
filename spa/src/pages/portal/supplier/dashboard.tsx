import { cn } from '@/lib/cn';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Chip } from '@/components/ui/Chip';
import { formatPeso } from '@/lib/formatNumber';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function SupplierDashboardPage() {
  const navigate = useNavigate();
  const { data: dashboard, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'dashboard'],
    queryFn: () => supplierPortalApi.dashboard(),
  });

  return (
    <div>
      <PageHeader title="Dashboard" subtitle="Purchase orders, deliveries, and payment status at a glance" />

      {/* One padded body holds every state, so loading and loaded agree on width. */}
      <div className="px-5 py-4 space-y-4 max-w-5xl">
        {isLoading && (
          <>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {Array.from({ length: 4 }).map((_, i) => (
                <SkeletonBlock key={i} className="h-24 rounded-md" />
              ))}
            </div>
            <SkeletonBlock className="h-48 rounded-md" />
            <SkeletonBlock className="h-48 rounded-md" />
          </>
        )}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load dashboard"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {!isLoading && !isError && (
          <>
            {/* Stats */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              <StatCard
                label="Open POs"
                value={dashboard?.open_po_count ?? 0}
                helper="Pending fulfillment"
              />
              <StatCard
                label="Pending Deliveries"
                value={dashboard?.pending_delivery_count ?? 0}
                helper="Awaited deliveries"
              />
              <StatCard
                label="Unpaid Invoices"
                value={dashboard?.unpaid_invoice_count ?? 0}
                helper="Invoices due"
              />
              <StatCard
                label="Total Unpaid"
                value={dashboard?.total_unpaid_amount ? formatPeso(dashboard.total_unpaid_amount) : '₱0'}
                helper="Outstanding balance"
              />
            </div>

            {/* Recent POs */}
            <Panel title="Recent Purchase Orders" actions={
              <Link to="/portal/supplier/purchase-orders" className="text-2xs text-accent hover:underline flex items-center gap-1">
                View all <ArrowRight size={11} />
              </Link>
            }>
              {dashboard?.recent_pos && dashboard.recent_pos.length > 0 ? (
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
                    {dashboard.recent_pos.map((po) => (
                      <tr key={po.id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate(`/portal/supplier/purchase-orders/${po.id}`)}>
                        <Td>
                          
                            {po.po_number}
                          
                        </Td>
                        <Td className="text-muted">{po.date ?? '—'}</Td>
                        <Td align="right" mono>{formatPeso(po.total_amount)}</Td>
                        <Td align="right" mono>
                          <Chip variant="neutral">{po.status}</Chip>
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <EmptyState icon="file-text" title="No purchase orders yet" />
              )}
            </Panel>

            {/* Recent Invoices */}
            <Panel title="Recent Invoices" actions={
              <Link to="/portal/supplier/invoices" className="text-2xs text-accent hover:underline flex items-center gap-1">
                View all <ArrowRight size={11} />
              </Link>
            }>
              {dashboard?.recent_invoices && dashboard.recent_invoices.length > 0 ? (
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
                    {dashboard.recent_invoices.map((inv) => (
                      <tr key={inv.id} className={cn(trCls, "cursor-pointer")} onClick={() => navigate(`/portal/supplier/invoices/${inv.id}`)}>
                        <Td>
                          
                            {inv.bill_number}
                          
                        </Td>
                        <Td className="text-muted">{inv.date ?? '—'}</Td>
                        <Td align="right" mono>{formatPeso(inv.total_amount)}</Td>
                        <Td align="right" mono>
                          <Chip variant={inv.status === 'paid' ? 'success' : inv.status === 'overdue' ? 'danger' : 'warning'}>
                            {inv.status}
                          </Chip>
                        </Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <EmptyState icon="receipt" title="No invoices yet" />
              )}
            </Panel>
          </>
        )}
      </div>
    </div>
  );
}
