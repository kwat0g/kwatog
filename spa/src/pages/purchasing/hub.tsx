import { useQuery } from '@tanstack/react-query';
import { FileText, ShoppingCart, Building, GitBranch, BarChart } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';

export default function PurchasingHubPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['purchasing', 'hub'],
    queryFn: () => dashboardsApi.purchasing(),
    refetchInterval: 60_000,
  });

  const pendingPrs = data?.kpis?.find((k: any) => k.label === 'Pending PRs')?.value ?? '0';
  const openPos = data?.kpis?.find((k: any) => k.label === 'Open POs')?.value ?? '0';
  const approvedSuppliers = data?.kpis?.find((k: any) => k.label === 'Approved Suppliers')?.value ?? '0';
  const avgLeadTime = data?.kpis?.find((k: any) => k.label === 'Avg Lead Time')?.value ?? '0';

  const stats: HubStat[] = [
    { label: 'Pending PRs', value: pendingPrs, linkTo: '/purchasing/purchase-requests' },
    { label: 'Open POs', value: openPos, linkTo: '/purchasing/purchase-orders' },
    { label: 'Approved Suppliers', value: approvedSuppliers, linkTo: '/purchasing/suppliers' },
    { label: 'Avg Lead Time', value: `${avgLeadTime} days` },
  ];

  const prPipeline = (data?.panels?.pr_pipeline as any[]) ?? [];
  const recentPos = (data?.panels?.recent_pos as any[]) ?? [];

  return (
    <HubPage title="Procurement" subtitle="Purchase requests, purchase orders, and supplier management" breadcrumbs={[{ label: 'Purchasing' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="PR Pipeline" icon={FileText} viewAllHref="/purchasing/purchase-requests">
              {prPipeline.length === 0 ? (
                <p className="text-sm text-muted">No pending PRs.</p>
              ) : (
                <div className="space-y-2">
                  {prPipeline.slice(0, 5).map((item: any, idx: number) => (
                    <div key={idx} className="flex items-center justify-between text-sm">
                      <span className="text-primary">{item.status}</span>
                      <span className="font-mono tabular-nums text-muted">{item.count}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Recent POs" icon={ShoppingCart} viewAllHref="/purchasing/purchase-orders">
              {recentPos.length === 0 ? (
                <p className="text-sm text-muted">No recent purchase orders.</p>
              ) : (
                <div className="space-y-2">
                  {recentPos.slice(0, 5).map((po: any) => (
                    <div key={po.id} className="flex items-center justify-between text-sm">
                      <Link to={`/purchasing/purchase-orders/${po.id}`} className="text-accent hover:underline">{po.po_no}</Link>
                      <Chip variant={po.status === 'approved' ? 'success' : po.status === 'pending' ? 'warning' : 'neutral'} >{po.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/purchasing/purchase-requests" icon={FileText} label="Purchase Requests" description="Material requisitions" />
              <NavTile to="/purchasing/purchase-orders" icon={ShoppingCart} label="Purchase Orders" description="Supplier POs" />
              <NavTile to="/purchasing/suppliers" icon={Building} label="Approved Suppliers" description="Supplier registry" />
              <NavTile to="/purchasing/pr-templates" icon={FileText} label="PR Templates" description="Recurring purchase patterns" />
              <NavTile to="/purchasing/procurement-chain" icon={GitBranch} label="Procurement Chain" description="End-to-end tracking" />
              <NavTile to="/purchasing/supplier-performance" icon={BarChart} label="Supplier Performance" description="Lead time and quality" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
