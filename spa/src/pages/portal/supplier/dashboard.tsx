import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';

export default function SupplierDashboardPage() {
  const { data: dashboard, isLoading } = useQuery({
    queryKey: ['portal', 'supplier', 'dashboard'],
    queryFn: () => supplierPortalApi.dashboard(),
  });

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <SkeletonBlock key={i} className="h-24 rounded-lg" />
          ))}
        </div>
        <SkeletonBlock className="h-48 rounded-lg" />
        <SkeletonBlock className="h-48 rounded-lg" />
      </div>
    );
  }

  return (
    <div className="space-y-4 max-w-5xl">
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
          value={dashboard?.total_unpaid_amount ? `₱${Number(dashboard.total_unpaid_amount).toLocaleString()}` : '₱0'}
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
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">PO #</th>
                <th className="text-left py-2 px-3 font-medium">Date</th>
                <th className="text-right py-2 px-3 font-medium">Amount</th>
                <th className="text-right py-2 px-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {dashboard.recent_pos.map((po) => (
                <tr key={po.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                  <td className="py-2 px-3">
                    <Link to={`/portal/supplier/purchase-orders/${po.id}`} className="font-mono text-accent hover:underline">
                      {po.po_number}
                    </Link>
                  </td>
                  <td className="py-2 px-3 text-muted">{po.date ?? '—'}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(po.total_amount).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right">
                    <span className="inline-block px-2 py-0.5 rounded-full text-2xs font-medium bg-subtle text-muted uppercase">
                      {po.status}
                    </span>
                  </td>
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
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-border text-muted">
                <th className="text-left py-2 px-3 font-medium">Invoice #</th>
                <th className="text-left py-2 px-3 font-medium">Date</th>
                <th className="text-right py-2 px-3 font-medium">Amount</th>
                <th className="text-right py-2 px-3 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {dashboard.recent_invoices.map((inv) => (
                <tr key={inv.id} className="border-b border-border/50 hover:bg-subtle/50 transition-colors">
                  <td className="py-2 px-3">
                    <Link to={`/portal/supplier/invoices/${inv.id}`} className="font-mono text-accent hover:underline">
                      {inv.invoice_number}
                    </Link>
                  </td>
                  <td className="py-2 px-3 text-muted">{inv.date ?? '—'}</td>
                  <td className="py-2 px-3 text-right font-mono">₱{Number(inv.total_amount).toLocaleString()}</td>
                  <td className="py-2 px-3 text-right">
                    <span className={`inline-block px-2 py-0.5 rounded-full text-2xs font-medium uppercase ${
                      inv.status === 'paid' ? 'bg-success/10 text-success' :
                      inv.status === 'overdue' ? 'bg-danger/10 text-danger' :
                      'bg-warning/10 text-warning'
                    }`}>
                      {inv.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <EmptyState icon="receipt" title="No invoices yet" />
        )}
      </Panel>
    </div>
  );
}
