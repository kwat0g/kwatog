import { PortalTable } from '@/components/portal/PortalTable';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { LuArrowRight } from '@/lib/icons';
import { customerPortalApi } from '@/api/b2b/customer';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatPeso } from '@/lib/formatNumber';
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';

export default function CustomerDashboardPage() {
 const { data: dashboard, isLoading, isError, refetch } = useQuery({
 queryKey: ['portal', 'customer', 'dashboard'],
 queryFn: () => customerPortalApi.dashboard(),
 });

 return (
 <div>
 <PageHeader title="Dashboard" subtitle="Orders, deliveries, and account balance at a glance" />

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
 label="Open Orders"
 value={dashboard?.open_so_count ?? '—'}
 helper="Pending fulfillment"
 linkTo="/portal/customer/orders"
 />
 <StatCard
 label="Pending Deliveries"
 value={dashboard?.pending_delivery_count ?? '—'}
 helper="Awaited deliveries"
 linkTo="/portal/customer/deliveries"
 />
 <StatCard
 label="Open Invoices"
 value={dashboard?.open_invoice_count ?? '—'}
 helper="Invoices due"
 linkTo="/portal/customer/invoices"
 />
 <StatCard
 label="Outstanding"
 value={dashboard ? formatPeso(dashboard.total_outstanding) : '—'}
 helper="Total balance"
 linkTo="/portal/customer/statement-of-account"
 />
 </div>

 {/* Recent Orders */}
 <Panel title="Recent Orders" actions={
 <Link to="/portal/customer/orders" className="text-2xs text-accent hover:underline flex items-center gap-1">
 View all <LuArrowRight size={11} />
 </Link>
 }>
 {dashboard?.recent_orders && dashboard.recent_orders.length > 0 ? (
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
 {dashboard.recent_orders.map((order) => (
 <tr key={order.id} className={trCls}>
 <Td>
 <Link to={`/portal/customer/orders/${order.id}`} className="font-mono font-medium text-accent hover:underline">
 {order.so_number}
 </Link>
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
</PortalTable>
 ) : (
 <EmptyState icon="package" title="No orders yet" />
 )}
 </Panel>

 {/* Recent Invoices */}
 <Panel title="Recent Invoices" actions={
 <Link to="/portal/customer/invoices" className="text-2xs text-accent hover:underline flex items-center gap-1">
 View all <LuArrowRight size={11} />
 </Link>
 }>
 {dashboard?.recent_invoices && dashboard.recent_invoices.length > 0 ? (
 <PortalTable>
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
 <tr key={inv.id} className={trCls}>
 <Td>
 <Link to={`/portal/customer/invoices/${inv.id}`} className="font-mono font-medium text-accent hover:underline">
 {inv.invoice_number}
 </Link>
 </Td>
 <Td className="text-muted">{inv.date ?? '—'}</Td>
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
</PortalTable>
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
