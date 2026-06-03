import { useQuery } from '@tanstack/react-query';
import { ShoppingCart, Package, DollarSign, MessageSquare, Users } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { salesOrdersApi } from '@/api/crm/salesOrders';
import { complaintsApi } from '@/api/crm/complaints';

export default function CrmHubPage() {
  const { data: salesOrders, isLoading: loadingSO } = useQuery({
    queryKey: ['crm', 'sales-orders', 'hub'],
    queryFn: () => salesOrdersApi.list({ per_page: 5 }),
    refetchInterval: 60_000,
  });

  const { data: complaints, isLoading: loadingComplaints } = useQuery({
    queryKey: ['crm', 'complaints', 'hub'],
    queryFn: () => complaintsApi.list({ per_page: 5, status: 'open' }),
    refetchInterval: 60_000,
  });

  const isLoading = loadingSO || loadingComplaints;

  const activeCustomers = 0; // Would need dedicated endpoint
  const openSO = salesOrders?.meta?.total ?? 0;
  const pendingComplaints = complaints?.meta?.total ?? 0;
  const products = 0; // Would need dedicated endpoint

  const stats: HubStat[] = [
    { label: 'Active Customers', value: activeCustomers, linkTo: '/crm/customers' },
    { label: 'Open Sales Orders', value: openSO, linkTo: '/crm/sales-orders' },
    { label: 'Pending Complaints', value: pendingComplaints, linkTo: '/crm/complaints' },
    { label: 'Products', value: products, linkTo: '/crm/products' },
  ];

  return (
    <HubPage title="CRM / Sales" subtitle="Customer relationships, sales orders, and product management" breadcrumbs={[{ label: 'CRM' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Recent Sales Orders" icon={ShoppingCart} viewAllHref="/crm/sales-orders">
              {!salesOrders?.data || salesOrders.data.length === 0 ? (
                <p className="text-sm text-muted">No recent sales orders.</p>
              ) : (
                <div className="space-y-2">
                  {salesOrders.data.slice(0, 5).map((so: any) => (
                    <div key={so.id} className="flex items-center justify-between text-sm">
                      <Link to={`/crm/sales-orders/${so.id}`} className="text-accent hover:underline">{so.so_no}</Link>
                      <Chip variant={so.status === 'confirmed' ? 'success' : so.status === 'draft' ? 'warning' : 'neutral'} >{so.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Open Complaints" icon={MessageSquare} viewAllHref="/crm/complaints">
              {!complaints?.data || complaints.data.length === 0 ? (
                <p className="text-sm text-muted">No open complaints.</p>
              ) : (
                <div className="space-y-2">
                  {complaints.data.slice(0, 5).map((complaint: any) => (
                    <div key={complaint.id} className="flex items-center justify-between text-sm">
                      <Link to={`/crm/complaints/${complaint.id}`} className="text-accent hover:underline truncate">{complaint.title || complaint.complaint_no}</Link>
                      <Chip variant={complaint.severity === 'critical' ? 'danger' : complaint.severity === 'major' ? 'warning' : 'info'} >{complaint.severity}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/crm/sales-orders" icon={ShoppingCart} label="Sales Orders" description="Customer orders and fulfillment" />
              <NavTile to="/crm/products" icon={Package} label="Products" description="Finished goods catalog" />
              <NavTile to="/crm/price-agreements" icon={DollarSign} label="Price Agreements" description="Customer pricing contracts" />
              <NavTile to="/crm/complaints" icon={MessageSquare} label="Complaints" description="Customer complaints and 8D" />
              <NavTile to="/accounting/customers" icon={Users} label="Customers" description="Customer accounts" />
              <NavTile to="/forecasting/demand" icon={ShoppingCart} label="Demand Forecast" description="Projected customer demand" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
