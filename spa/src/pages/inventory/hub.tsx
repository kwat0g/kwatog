/**
 * S2 — Inventory Hub (tab-based pattern)
 *
 * Data dashboard for inventory management. Each tab shows real inline data.
 * Config/reference pages (categories, warehouses, movements, map) accessible via tabs.
 * Workflow pages (GRN, issuance) stay in sidebar.
 */
import { useSearchParams, Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { inventoryDashboardApi } from '@/api/inventory/dashboard';
import { itemsApi, itemCategoriesApi } from '@/api/inventory/items';
import { warehouseApi } from '@/api/inventory/warehouse';
import { stockMovementsApi } from '@/api/inventory/stock';
import { PageHeader } from '@/components/layout/PageHeader';
import { TabNavigation, type Tab } from '@/components/ui/TabNavigation';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { formatDate } from '@/lib/formatDate';

const TABS: Tab[] = [
  { key: 'stock-levels', label: 'Stock Levels', to: '/inventory/hub?tab=stock-levels' },
  { key: 'warehouses', label: 'Warehouses', to: '/inventory/hub?tab=warehouses' },
  { key: 'categories', label: 'Categories', to: '/inventory/hub?tab=categories' },
  { key: 'movements', label: 'Movements', to: '/inventory/hub?tab=movements' },
];

/** Quick-action buttons shown at the top of the hub */
function QuickActions() {
  const quickLinks = [
    { label: 'Items',            to: '/inventory/items',                       icon: '📦' },
    { label: 'GRN',              to: '/inventory/grn',                         icon: '📥' },
    { label: 'Issue',            to: '/inventory/material-issues',             icon: '📤' },
    { label: 'Adjustment',       to: '/inventory/stock-adjustments/create',    icon: '⚙️' },
    { label: 'Transfer',         to: '/inventory/transfer-orders',             icon: '🚚' },
    { label: 'Stock Count',      to: '/inventory/stock-count',                 icon: '📋' },
    { label: 'Picking',          to: '/inventory/picking',                     icon: '📦' },
    { label: 'Warehouse Map',    to: '/inventory/warehouse-map',               icon: '🗺️' },
  ];
  return (
    <div className="px-5 pt-4 pb-2">
      <div className="flex items-center gap-2 flex-wrap">
        {quickLinks.map((link) => (
          <Link
            key={link.to}
            to={link.to}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-md border border-default bg-canvas text-secondary hover:bg-elevated hover:text-primary hover:border-accent transition-all duration-fast"
          >
            <span aria-hidden>{link.icon}</span>
            {link.label}
          </Link>
        ))}
      </div>
    </div>
  );
}

export default function InventoryHubPage() {
  const [searchParams] = useSearchParams();
  const activeTab = searchParams.get('tab') ?? 'stock-levels';

  return (
    <div>
      <PageHeader
        title="Inventory & Warehouse"
        subtitle="Material Management"
        breadcrumbs={[
          { label: 'Inventory', href: '/inventory/hub' },
          { label: 'Hub' },
        ]}
      />
      <QuickActions />
      <TabNavigation tabs={TABS} defaultKey="stock-levels" />
      <div className="px-5 py-4">
        {activeTab === 'stock-levels' && <StockLevelsTab />}
        {activeTab === 'warehouses' && <WarehousesTab />}
        {activeTab === 'categories' && <CategoriesTab />}
        {activeTab === 'movements' && <MovementsTab />}
      </div>
    </div>
  );
}

/* ─── Stock Levels Tab ─────────────────────────────────── */

function StockLevelsTab() {
  const { data: summary, isLoading: summaryLoading } = useQuery({
    queryKey: ['inventory-hub', 'summary'],
    queryFn: () => inventoryDashboardApi.summary(),
    retry: false,
  });

  const { data: lowStockData, isLoading: lowStockLoading } = useQuery({
    queryKey: ['inventory-hub', 'low-stock'],
    queryFn: () => itemsApi.list({ filter_stock: 'low', per_page: 10, sort: 'current_stock', direction: 'asc' }),
    retry: false,
  });

  const isLoading = summaryLoading || lowStockLoading;

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const lowStockItems = lowStockData?.data ?? [];
  const totalItems = lowStockData?.meta?.total ?? 0;
  const itemsBelowReorder = summary?.items_below_reorder ?? 0;
  const totalStockValue = summary?.total_stock_value ?? '0.00';
  const pendingGrns = summary?.pending_grns ?? 0;

  return (
    <div className="space-y-4">
      {/* Stat cards */}
      <div className="grid grid-cols-4 gap-3">
        <div className="rounded-lg border border-default p-3">
          <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Items</p>
          <p className="text-2xl font-semibold mt-1">{totalItems}</p>
        </div>
        <div className="rounded-lg border border-default p-3">
          <p className="text-2xs text-text-subtle uppercase tracking-wider">Low Stock</p>
          <p className="text-2xl font-semibold mt-1">{itemsBelowReorder}</p>
        </div>
        <div className="rounded-lg border border-default p-3">
          <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Value</p>
          <p className="text-2xl font-semibold mt-1 font-mono">
            ₱{parseFloat(totalStockValue).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
          </p>
        </div>
        <div className="rounded-lg border border-default p-3">
          <p className="text-2xs text-text-subtle uppercase tracking-wider">Pending GRN</p>
          <p className="text-2xl font-semibold mt-1">{pendingGrns}</p>
        </div>
      </div>

      {lowStockItems.length === 0 ? (
        <EmptyState icon="box" title="No low stock items" description="All items are above reorder level."
          action={<Link to="/inventory/items" className="text-sm text-accent hover:underline">View all items →</Link>} />
      ) : (
        <>
          <Panel title="Low Stock Alerts" actions={<Link to="/inventory/stock-levels" className="text-sm text-accent hover:underline">View all →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Item Code</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Current</th>
                    <th className="py-2 pr-3 font-medium">Reorder Level</th>
                    <th className="py-2 font-medium">Unit</th>
                  </tr>
                </thead>
                <tbody>
                  {lowStockItems.slice(0, 10).map((item: any) => (
                    <tr key={item.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3">
                        <Link to={`/inventory/items/${item.id}`} className="text-accent hover:underline font-mono text-xs">
                          {item.item_code}
                        </Link>
                      </td>
                      <td className="py-2 pr-3 font-medium">{item.name}</td>
                      <td className="py-2 pr-3 font-mono tabular-nums text-danger">{item.current_stock ?? 0}</td>
                      <td className="py-2 pr-3 font-mono tabular-nums">{item.reorder_level ?? 0}</td>
                      <td className="py-2 text-xs text-secondary">{item.unit ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/inventory/stock-levels" className="text-sm text-accent hover:underline">View all stock levels →</Link>
            <Link to="/inventory/items/create" className="text-sm text-accent hover:underline">New item →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Warehouses Tab ───────────────────────────────────── */

function WarehousesTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['inventory-hub', 'warehouses'],
    queryFn: () => warehouseApi.listWarehouses(),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const warehouses = data ?? [];
  const active = warehouses.filter((w: any) => w.is_active);

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load warehouses"
          action={<Link to="/inventory/warehouse" className="text-sm text-accent hover:underline">Go to warehouses →</Link>} />
      ) : warehouses.length === 0 ? (
        <EmptyState icon="warehouse" title="No warehouses configured" description="Add a warehouse to get started."
          action={<Link to="/inventory/warehouse" className="text-sm text-accent hover:underline">Manage warehouses →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Warehouses</p>
              <p className="text-2xl font-semibold mt-1">{warehouses.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Active</p>
              <p className="text-2xl font-semibold mt-1">{active.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Zones</p>
              <p className="text-2xl font-semibold mt-1">
                {warehouses.reduce((sum: number, w: any) => sum + (w.zones_count ?? 0), 0)}
              </p>
            </div>
          </div>
          <Panel title="Warehouse Locations" actions={<Link to="/inventory/warehouse" className="text-sm text-accent hover:underline">Manage →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Code</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Type</th>
                    <th className="py-2 pr-3 font-medium">Location</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {warehouses.slice(0, 10).map((w: any) => (
                    <tr key={w.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3 font-mono text-xs">{w.code}</td>
                      <td className="py-2 pr-3 font-medium">{w.name}</td>
                      <td className="py-2 pr-3 text-xs text-secondary">{w.type ?? 'Standard'}</td>
                      <td className="py-2 pr-3 text-xs text-muted max-w-[200px] truncate" title={w.address}>
                        {w.address ?? '—'}
                      </td>
                      <td className="py-2">
                        <Chip variant={w.is_active ? 'success' : 'neutral'}>
                          {w.is_active ? 'Active' : 'Inactive'}
                        </Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/inventory/warehouse" className="text-sm text-accent hover:underline">Manage warehouses →</Link>
            <Link to="/inventory/warehouse-map" className="text-sm text-accent hover:underline">View warehouse map →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Categories Tab ───────────────────────────────────── */

function CategoriesTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['inventory-hub', 'categories'],
    queryFn: () => itemCategoriesApi.list(),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const categories = data ?? [];
  const active = categories.filter((c: any) => c.is_active);

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load categories"
          action={<Link to="/inventory/categories" className="text-sm text-accent hover:underline">Go to categories →</Link>} />
      ) : categories.length === 0 ? (
        <EmptyState icon="folder" title="No categories configured" description="Add a category to organize items."
          action={<Link to="/inventory/categories" className="text-sm text-accent hover:underline">Manage categories →</Link>} />
      ) : (
        <>
          <div className="grid grid-cols-3 gap-3">
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Categories</p>
              <p className="text-2xl font-semibold mt-1">{categories.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Active</p>
              <p className="text-2xl font-semibold mt-1">{active.length}</p>
            </div>
            <div className="rounded-lg border border-default p-3">
              <p className="text-2xs text-text-subtle uppercase tracking-wider">Total Items</p>
              <p className="text-2xl font-semibold mt-1">
                {categories.reduce((sum: number, c: any) => sum + (c.items_count ?? 0), 0)}
              </p>
            </div>
          </div>
          <Panel title="Item Categories" actions={<Link to="/inventory/categories" className="text-sm text-accent hover:underline">Manage →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Code</th>
                    <th className="py-2 pr-3 font-medium">Name</th>
                    <th className="py-2 pr-3 font-medium">Items</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {categories.slice(0, 10).map((c: any) => (
                    <tr key={c.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3 font-mono text-xs">{c.code}</td>
                      <td className="py-2 pr-3 font-medium">{c.name}</td>
                      <td className="py-2 pr-3 font-mono tabular-nums">{c.items_count ?? 0}</td>
                      <td className="py-2">
                        <Chip variant={c.is_active ? 'success' : 'neutral'}>
                          {c.is_active ? 'Active' : 'Inactive'}
                        </Chip>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/inventory/categories" className="text-sm text-accent hover:underline">Manage categories →</Link>
          </div>
        </>
      )}
    </div>
  );
}

/* ─── Movements Tab ────────────────────────────────────── */

function MovementsTab() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['inventory-hub', 'movements'],
    queryFn: () => stockMovementsApi.list({ per_page: 20, sort: 'created_at', direction: 'desc' }),
    retry: false,
  });

  if (isLoading) return <div className="flex justify-center py-10"><Spinner /></div>;

  const movements = data?.data ?? [];

  const movementTypeLabel = (type: string) => {
    const labels: Record<string, string> = {
      grn: 'GRN',
      issue: 'Issue',
      adjustment: 'Adjustment',
      transfer: 'Transfer',
      return: 'Return',
    };
    return labels[type] ?? type;
  };

  const movementTypeVariant = (type: string) => {
    const variants: Record<string, 'success' | 'warning' | 'info' | 'neutral'> = {
      grn: 'success',
      issue: 'warning',
      adjustment: 'info',
      transfer: 'info',
      return: 'neutral',
    };
    return variants[type] ?? 'neutral';
  };

  return (
    <div className="space-y-4">
      {isError ? (
        <EmptyState icon="alert-circle" title="Could not load movements"
          action={<Link to="/inventory/movements" className="text-sm text-accent hover:underline">Go to movements →</Link>} />
      ) : movements.length === 0 ? (
        <EmptyState icon="activity" title="No recent movements" description="Transaction history will appear here."
          action={<Link to="/inventory/movements" className="text-sm text-accent hover:underline">View all movements →</Link>} />
      ) : (
        <>
          <Panel title="Recent Transactions" actions={<Link to="/inventory/movements" className="text-sm text-accent hover:underline">View all →</Link>}>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-default text-left text-2xs uppercase tracking-wider text-text-subtle">
                    <th className="py-2 pr-3 font-medium">Date</th>
                    <th className="py-2 pr-3 font-medium">Type</th>
                    <th className="py-2 pr-3 font-medium">Item</th>
                    <th className="py-2 pr-3 font-medium">Qty</th>
                    <th className="py-2 pr-3 font-medium">Warehouse</th>
                    <th className="py-2 font-medium">Reference</th>
                  </tr>
                </thead>
                <tbody>
                  {movements.slice(0, 10).map((m: any) => (
                    <tr key={m.id} className="border-b border-default last:border-0 hover:bg-elevated/50">
                      <td className="py-2 pr-3 font-mono text-xs text-secondary">{formatDate(m.created_at)}</td>
                      <td className="py-2 pr-3">
                        <Chip variant={movementTypeVariant(m.type)}>{movementTypeLabel(m.type)}</Chip>
                      </td>
                      <td className="py-2 pr-3 font-medium max-w-[200px] truncate" title={m.item?.name}>
                        {m.item?.name ?? '—'}
                      </td>
                      <td className="py-2 pr-3 font-mono tabular-nums">
                        {m.quantity > 0 ? '+' : ''}{m.quantity}
                      </td>
                      <td className="py-2 pr-3 text-xs text-secondary">{m.warehouse?.name ?? '—'}</td>
                      <td className="py-2 font-mono text-xs text-accent hover:underline">{m.reference_no ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
          <div className="flex gap-3">
            <Link to="/inventory/movements" className="text-sm text-accent hover:underline">View all movements →</Link>
          </div>
        </>
      )}
    </div>
  );
}
