import { useQuery } from '@tanstack/react-query';
import { Package, AlertTriangle, FileText, Warehouse, Map, ClipboardList, Truck, ArrowRightLeft, BarChart3, FolderTree, PackageSearch, Settings } from 'lucide-react';
import { HubPage, HubCard, NavTile, type HubStat } from '@/components/hub';
import { Chip } from '@/components/ui/Chip';
import { Spinner } from '@/components/ui/Spinner';
import { Link } from 'react-router-dom';
import { inventoryDashboardApi } from '@/api/inventory/dashboard';

export default function InventoryHubPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['inventory', 'hub'],
    queryFn: () => inventoryDashboardApi.summary(),
    refetchInterval: 60_000,
  });

  const stats: HubStat[] = [
    { label: 'Total Items', value: data?.low_stock_alerts?.length ?? 0, linkTo: '/inventory/items' },
    { label: 'Low Stock', value: data?.items_below_reorder ?? 0, linkTo: '/inventory/stock-levels' },
    { label: 'Pending GRN', value: data?.pending_grns ?? 0, linkTo: '/inventory/grn' },
    { label: 'Inventory Value', value: `₱${data?.total_stock_value ? parseFloat(data.total_stock_value).toLocaleString('en-PH', { minimumFractionDigits: 2 }) : '0.00'}` },
  ];

  const lowStockItems = data?.low_stock_alerts ?? [];
  const recentGrn = data?.recent_movements ?? [];

  return (
    <HubPage title="Inventory & Warehouse" subtitle="Material management, stock control, and warehouse operations" breadcrumbs={[{ label: 'Inventory' }]} stats={isLoading ? undefined : stats}>
      {isLoading ? (
        <div className="flex justify-center py-12"><Spinner /></div>
      ) : (
        <>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <HubCard title="Low Stock Alerts" icon={AlertTriangle} viewAllHref="/inventory/stock-levels">
              {lowStockItems.length === 0 ? (
                <p className="text-sm text-muted">No low stock items.</p>
              ) : (
                <div className="space-y-2">
                  {lowStockItems.slice(0, 5).map((item: any) => (
                    <div key={item.id} className="flex items-center justify-between text-sm">
                      <Link to={`/inventory/items/${item.id}`} className="text-accent hover:underline truncate">{item.name}</Link>
                      <span className="font-mono tabular-nums text-muted">{item.current_stock} / {item.reorder_level}</span>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>

            <HubCard title="Recent GRN" icon={FileText} viewAllHref="/inventory/grn">
              {recentGrn.length === 0 ? (
                <p className="text-sm text-muted">No recent goods receipts.</p>
              ) : (
                <div className="space-y-2">
                  {recentGrn.slice(0, 5).map((grn: any) => (
                    <div key={grn.id} className="flex items-center justify-between text-sm">
                      <Link to={`/inventory/grn/${grn.id}`} className="text-accent hover:underline">{grn.grn_no}</Link>
                      <Chip variant={grn.status === 'completed' ? 'success' : grn.status === 'pending' ? 'warning' : 'neutral'} >{grn.status}</Chip>
                    </div>
                  ))}
                </div>
              )}
            </HubCard>
          </div>

          <div>
            <h3 className="text-xs font-medium text-muted uppercase tracking-wider mb-3">All Sections</h3>
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
              <NavTile to="/inventory/items" icon={Package} label="Items" description="Product catalog and SKUs" />
              <NavTile to="/inventory/categories" icon={FolderTree} label="Categories" description="Item classification" />
              <NavTile to="/inventory/stock-levels" icon={BarChart3} label="Stock Levels" description="Current inventory by item" />
              <NavTile to="/inventory/warehouse" icon={Warehouse} label="Warehouses" description="Storage locations" />
              <NavTile to="/inventory/warehouse-map" icon={Map} label="Warehouse Map" description="Bin locations and layout" />
              <NavTile to="/inventory/grn" icon={FileText} label="GRN" description="Goods receipt notes" />
              <NavTile to="/inventory/material-issues" icon={PackageSearch} label="Material Issues" description="Inventory releases" />
              <NavTile to="/inventory/movements" icon={ArrowRightLeft} label="Movements" description="Transaction history" />
              <NavTile to="/inventory/stock-count" icon={ClipboardList} label="Stock Count" description="Physical inventory" />
              <NavTile to="/inventory/picking" icon={Package} label="Picking" description="Order picking lists" />
              <NavTile to="/inventory/transfer-orders" icon={Truck} label="Transfers" description="Inter-warehouse transfers" />
              <NavTile to="/inventory/stock-adjustments/create" icon={Settings} label="Stock Adjustments" description="Manual adjustments" />
            </div>
          </div>
        </>
      )}
    </HubPage>
  );
}
