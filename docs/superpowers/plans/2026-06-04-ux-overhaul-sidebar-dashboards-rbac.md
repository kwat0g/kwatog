# UX Overhaul: Sidebar Consolidation, Hub Pages, Role Dashboards & RBAC

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the sidebar to only essential pages, create data-rich hub pages for secondary features, upgrade role dashboards with charts and real analytics, absorb standalone analytics pages into dashboards, and implement dynamic RBAC management.

**Architecture:** Sidebar reduced from 30+ items to ~15 primary links. Secondary pages become hub pages showing live data cards/tables. Role dashboards get Recharts integration for professional analytics. RBAC admin gets a live permission matrix with drag-drop and search.

**Tech Stack:** React 18, TypeScript, Recharts (charts), TanStack Query, Zustand, Tailwind CSS, Laravel 11 backend

---

## Phase 1: Sidebar Consolidation & Hub Pages

### Problem Statement

Current sidebar has 30+ items across 11 sections. Many pages are orphaned (exist in routes but unreachable via navigation). Need to:
1. Reduce sidebar to ~15 essential links
2. Create hub pages for each module grouping secondary pages
3. Hub pages show **real data** (stats, recent records, quick actions) — not just buttons
4. Ensure EVERY page is reachable via navigation (zero orphans)

### Orphaned Pages Identified (no sidebar/hub access currently)

- `/hr/departments` — no link
- `/hr/positions` — no link
- `/hr/profile-update-requests` — no link (only badge on employees)
- `/hr/directory` — no link
- `/hr/separations` — no link
- `/inventory/categories` — no link
- `/inventory/warehouse` — no link
- `/inventory/stock-levels` — no link
- `/inventory/movements` — no link
- `/inventory/stock-adjustments/create` — no link
- `/inventory/stock-transfers/create` — no link
- `/inventory/warehouse-map` — no link
- `/inventory/stock-count` — no link
- `/inventory/picking` — no link
- `/inventory/transfer-orders` — no link
- `/accounting/coa` — no link (default redirect from /accounting)
- `/accounting/vendors` — no link
- `/accounting/trial-balance` — no link
- `/accounting/income-statement` — no link
- `/accounting/balance-sheet` — no link
- `/crm/products` — no link
- `/crm/price-agreements` — no link
- `/crm/complaints` — no link
- `/mrp/boms` — no link
- `/mrp/machines` — no link
- `/mrp/molds` — no link
- `/supply-chain/fleet` — no link
- `/purchasing/approved-suppliers` — no link
- `/purchasing/pr-templates` — no link
- `/purchasing/chain` — no link
- `/production/dashboard` — no link
- `/production/oee` — no link
- `/maintenance/machine-health` — no link
- `/maintenance/downtime` — no link
- `/maintenance/schedules` — no link
- `/quality/inspection-specs` — no link
- `/quality/traceability` — no link
- `/quality/ncr-templates` — no link
- `/forecasting/stock-out` — no link
- `/budgeting/*` (all except overview) — no link
- `/return-management` — no link
- `/assets` (detail/create) — no link from sidebar beyond list
- `/admin/scheduled-exports` — no link
- `/admin/gov-tables` — no link (only in payroll hub)
- `/admin/activity` — no link

### New Sidebar Structure (Final)

```
OVERVIEW
  Dashboard          /dashboard
  Approvals          /approvals              [badge: approvals]
  Notifications      /notifications          [badge: unread]

SALES & CRM
  Sales Orders       /crm/sales-orders       [badge: pending_so]
  Customers          /accounting/customers

PRODUCTION
  Work Orders        /production/work-orders  [badge: work_orders]
  MRP Plans          /mrp/plans
  Schedule           /production/schedule

PROCUREMENT
  Purchase Orders    /purchasing/purchase-orders  [badge: purchase_requests]
  Purchase Requests  /purchasing/purchase-requests

WAREHOUSE & INVENTORY
  Inventory          /inventory/hub           [badge: low_stock]
  Receiving (GRN)    /inventory/grn
  Material Issues    /inventory/material-issues

SUPPLY CHAIN
  Deliveries         /supply-chain/deliveries [badge: deliveries]

QUALITY
  Quality            /quality/hub             [badge: ncrs]

FINANCE
  Journal Entries    /accounting/journal-entries
  Invoices (AR)      /accounting/invoices
  Bills (AP)         /accounting/bills

HUMAN RESOURCES
  Employees          /hr/employees            [badge: profile_requests]
  Attendance         /hr/attendance/hub       [badge: leaves + overtime]
  Leave              /hr/leaves
  Payroll            /payroll/hub             [badge: payroll]

MAINTENANCE
  Maintenance        /maintenance/hub         [badge: maintenance_wo]

ADMIN
  Users & Roles      /admin/users-roles
  Settings           /admin/settings
  Audit Logs         /admin/audit-logs
```

**Removed from sidebar → accessible via hub pages:**
- Calendar, Alerts → Dashboard (widgets)
- Demand Forecast → Dashboard (embedded)
- Loans → HR Employees hub or Payroll hub
- Shipments → Supply Chain deliveries hub
- Budgets → Finance hub or Finance Dashboard
- Assets → Maintenance hub
- MRP BOMs/Machines/Molds → MRP hub (accessible from MRP Plans page)

### New Hub Pages Required

| Hub | URL | Shows |
|-----|-----|-------|
| Inventory Hub | `/inventory/hub` | Stock levels summary, low stock alerts, recent movements, quick nav to all inventory sub-pages |
| Quality Hub | `/quality/hub` | Open NCRs, pending inspections, defect rate mini-chart, links to specs/traceability/templates |
| Maintenance Hub | `/maintenance/hub` | Open WOs, machine health summary, upcoming schedules, links to downtime/assets |
| CRM Hub | `/crm/hub` | Pipeline summary, recent complaints, products stats, links to products/price agreements |
| MRP Hub | `/mrp/hub` | Active plans summary, BOM count, machine utilization, mold shot warnings |
| Finance Hub | `/accounting/hub` | AR/AP totals, recent JEs, financial statements links, budget overview, vendor/customer counts |
| Purchasing Hub | `/purchasing/hub` | PR pipeline, PO status breakdown, supplier performance summary, approved suppliers |
| Supply Chain Hub | `/supply-chain/hub` | Active deliveries, shipment tracking, fleet status |
| Admin Hub | `/admin/hub` | User stats, recent audit activity, scheduled exports, gov tables link |
| HR Hub | `/hr/hub` | Headcount stats, department breakdown, recent separations, directory link, loans overview |

---

### Task 1: Install Recharts

**Files:**
- Modify: `spa/package.json`

- [ ] **Step 1: Install recharts**

```bash
cd spa && npm install recharts
```

- [ ] **Step 2: Verify installation**

```bash
cd spa && npx tsc --noEmit 2>&1 | head -20
```

- [ ] **Step 3: Commit**

```bash
git add spa/package.json spa/package-lock.json
git commit -m "chore: install recharts for dashboard charts"
```

---

### Task 2: Create HubPage layout component

**Files:**
- Create: `spa/src/components/layout/HubPage.tsx`
- Create: `spa/src/components/layout/HubCard.tsx`

Hub pages need a consistent layout: page header with breadcrumb, stat row, grid of data-rich cards, and quick-nav section.

- [ ] **Step 1: Create HubPage component**

```typescript
// spa/src/components/layout/HubPage.tsx
import { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { LucideIcon } from 'lucide-react';

interface HubStat {
  label: string;
  value: string | number;
  href?: string;
  variant?: 'default' | 'success' | 'warning' | 'danger';
}

interface HubPageProps {
  title: string;
  description?: string;
  icon?: LucideIcon;
  stats?: HubStat[];
  children: ReactNode;
}

export function HubPage({ title, description, icon: Icon, stats, children }: HubPageProps) {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-primary">{title}</h1>
        {description && <p className="text-sm text-muted mt-1">{description}</p>}
      </div>

      {stats && stats.length > 0 && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {stats.map((stat) => {
            const content = (
              <div className="rounded-md border border-default bg-surface p-4">
                <p className="text-xs text-muted uppercase tracking-wide">{stat.label}</p>
                <p className={`text-2xl font-semibold font-mono tabular-nums mt-1 ${
                  stat.variant === 'danger' ? 'text-danger' :
                  stat.variant === 'warning' ? 'text-warning' :
                  stat.variant === 'success' ? 'text-success' :
                  'text-primary'
                }`}>{stat.value}</p>
              </div>
            );
            return stat.href ? (
              <Link key={stat.label} to={stat.href} className="hover:ring-1 hover:ring-accent rounded-md transition-shadow">
                {content}
              </Link>
            ) : (
              <div key={stat.label}>{content}</div>
            );
          })}
        </div>
      )}

      {children}
    </div>
  );
}
```

- [ ] **Step 2: Create HubCard component**

```typescript
// spa/src/components/layout/HubCard.tsx
import { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, LucideIcon } from 'lucide-react';

interface HubCardProps {
  title: string;
  icon?: LucideIcon;
  href?: string;
  viewAllHref?: string;
  viewAllLabel?: string;
  children: ReactNode;
  className?: string;
}

export function HubCard({ title, icon: Icon, href, viewAllHref, viewAllLabel, children, className = '' }: HubCardProps) {
  return (
    <div className={`rounded-md border border-default bg-surface ${className}`}>
      <div className="flex items-center justify-between px-4 py-3 border-b border-default">
        <div className="flex items-center gap-2">
          {Icon && <Icon size={16} className="text-muted" />}
          <h3 className="text-sm font-medium text-primary">{title}</h3>
        </div>
        {viewAllHref && (
          <Link to={viewAllHref} className="text-xs text-accent hover:underline flex items-center gap-1">
            {viewAllLabel || 'View all'} <ArrowRight size={12} />
          </Link>
        )}
      </div>
      <div className="p-4">
        {children}
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Commit**

```bash
git add spa/src/components/layout/HubPage.tsx spa/src/components/layout/HubCard.tsx
git commit -m "feat: add HubPage and HubCard layout components"
```

---

### Task 3: Create Inventory Hub page

**Files:**
- Create: `spa/src/pages/inventory/hub.tsx`
- Modify: `spa/src/routes/inventoryRoutes.tsx` (add hub route)

This is the template for all hub pages. Shows real data: stock stats, low stock alerts table, recent movements, and navigation cards to all sub-pages.

- [ ] **Step 1: Create inventory hub page**

```typescript
// spa/src/pages/inventory/hub.tsx
import { useQuery } from '@tanstack/react-query';
import { Package, AlertTriangle, ArrowDownUp, Warehouse, BarChart3, ClipboardList, Truck, ArrowUpDown, MapPin, ListChecks } from 'lucide-react';
import { Link } from 'react-router-dom';
import { HubPage } from '../../components/layout/HubPage';
import { HubCard } from '../../components/layout/HubCard';
import { Chip } from '../../components/ui/Chip';
import { SkeletonBlock } from '../../components/ui/Skeleton';
import { inventoryApi } from '../../api/inventory';
import { CanDo } from '../../components/guards/CanDo';

export default function InventoryHubPage() {
  const { data: dashboard, isLoading } = useQuery({
    queryKey: ['inventory', 'dashboard'],
    queryFn: () => inventoryApi.dashboard().then(r => r.data),
    refetchInterval: 60_000,
  });

  if (isLoading) return <SkeletonBlock lines={12} />;

  const stats = [
    { label: 'Total Items', value: dashboard?.data?.total_items ?? '—' },
    { label: 'Low Stock', value: dashboard?.data?.low_stock_count ?? 0, variant: 'danger' as const, href: '/inventory/stock-levels' },
    { label: 'Pending GRN', value: dashboard?.data?.pending_grn ?? 0, variant: 'warning' as const, href: '/inventory/grn' },
    { label: 'Total Value', value: `₱${(dashboard?.data?.total_value ?? 0).toLocaleString()}`, href: '/inventory/items' },
  ];

  return (
    <HubPage title="Inventory & Warehouse" description="Stock management, receiving, and material control" stats={stats}>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <HubCard title="Low Stock Alerts" icon={AlertTriangle} viewAllHref="/inventory/stock-levels" viewAllLabel="All stock levels">
          {dashboard?.data?.low_stock_items?.length ? (
            <table className="w-full text-sm">
              <tbody>
                {dashboard.data.low_stock_items.slice(0, 5).map((item: any) => (
                  <tr key={item.id} className="border-b border-default last:border-0">
                    <td className="py-2">
                      <Link to={`/inventory/items/${item.id}`} className="text-accent hover:underline">{item.name}</Link>
                    </td>
                    <td className="py-2 text-right font-mono tabular-nums">
                      <span className="text-danger">{item.available_qty}</span>
                      <span className="text-muted"> / {item.reorder_point}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="text-sm text-muted">All items above reorder point</p>
          )}
        </HubCard>

        <HubCard title="Recent Movements" icon={ArrowDownUp} viewAllHref="/inventory/movements" viewAllLabel="All movements">
          {dashboard?.data?.recent_movements?.length ? (
            <table className="w-full text-sm">
              <tbody>
                {dashboard.data.recent_movements.slice(0, 5).map((mov: any, i: number) => (
                  <tr key={i} className="border-b border-default last:border-0">
                    <td className="py-2">
                      <Chip variant={mov.type === 'in' ? 'success' : mov.type === 'out' ? 'danger' : 'info'} size="sm">
                        {mov.type}
                      </Chip>
                    </td>
                    <td className="py-2">{mov.item_name}</td>
                    <td className="py-2 text-right font-mono tabular-nums">{mov.quantity}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="text-sm text-muted">No recent movements</p>
          )}
        </HubCard>
      </div>

      {/* Navigation grid to all sub-pages */}
      <div className="mt-6">
        <h3 className="text-sm font-medium text-muted mb-3 uppercase tracking-wide">All Sections</h3>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
          <NavTile to="/inventory/items" icon={Package} label="Items" desc="Product & material catalog" />
          <NavTile to="/inventory/categories" icon={ListChecks} label="Categories" desc="Item classification" />
          <NavTile to="/inventory/stock-levels" icon={BarChart3} label="Stock Levels" desc="Current quantities" />
          <NavTile to="/inventory/warehouse" icon={Warehouse} label="Warehouses" desc="Storage locations" />
          <NavTile to="/inventory/warehouse-map" icon={MapPin} label="Warehouse Map" desc="Zone layout" />
          <NavTile to="/inventory/grn" icon={Truck} label="Receiving (GRN)" desc="Goods receipt" />
          <NavTile to="/inventory/material-issues" icon={ArrowUpDown} label="Material Issues" desc="Production issuance" />
          <NavTile to="/inventory/movements" icon={ArrowDownUp} label="Movements" desc="Stock history" />
          <NavTile to="/inventory/stock-count" icon={ClipboardList} label="Stock Count" desc="Physical inventory" />
          <NavTile to="/inventory/picking" icon={Package} label="Picking Lists" desc="Order fulfillment" />
          <NavTile to="/inventory/transfer-orders" icon={ArrowUpDown} label="Transfers" desc="Inter-warehouse" />
          <CanDo permission="inventory.adjust">
            <NavTile to="/inventory/stock-adjustments/create" icon={ArrowDownUp} label="Adjustment" desc="Stock correction" />
          </CanDo>
          <CanDo permission="inventory.adjust">
            <NavTile to="/inventory/stock-transfers/create" icon={ArrowUpDown} label="Transfer" desc="Move between zones" />
          </CanDo>
        </div>
      </div>
    </HubPage>
  );
}

function NavTile({ to, icon: Icon, label, desc }: { to: string; icon: LucideIcon; label: string; desc: string }) {
  return (
    <Link to={to} className="rounded-md border border-default bg-surface p-3 hover:border-accent hover:shadow-sm transition-all group">
      <Icon size={18} className="text-muted group-hover:text-accent mb-2" />
      <p className="text-sm font-medium text-primary">{label}</p>
      <p className="text-xs text-muted mt-0.5">{desc}</p>
    </Link>
  );
}
```

- [ ] **Step 2: Add hub route to inventoryRoutes.tsx**

Add before the existing inventory routes:
```typescript
{ index: true, element: <Navigate to="/inventory/hub" replace /> },
{ path: 'hub', element: <InventoryHubPage /> },
```

- [ ] **Step 3: Verify build**

```bash
cd spa && npx tsc --noEmit
```

- [ ] **Step 4: Commit**

```bash
git add spa/src/pages/inventory/hub.tsx spa/src/routes/inventoryRoutes.tsx
git commit -m "feat: add Inventory Hub page with live data and full sub-page navigation"
```

---

### Task 4: Create Quality Hub page

**Files:**
- Create: `spa/src/pages/quality/hub.tsx`
- Modify: `spa/src/routes/qualityRoutes.tsx`

- [ ] **Step 1: Create quality hub page**

```typescript
// spa/src/pages/quality/hub.tsx
import { useQuery } from '@tanstack/react-query';
import { Shield, AlertOctagon, ClipboardCheck, FileSearch, BookTemplate, Link2, TrendingDown } from 'lucide-react';
import { Link } from 'react-router-dom';
import { HubPage } from '../../components/layout/HubPage';
import { HubCard } from '../../components/layout/HubCard';
import { Chip } from '../../components/ui/Chip';
import { SkeletonBlock } from '../../components/ui/Skeleton';
import { qualityApi } from '../../api/quality';

export default function QualityHubPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['quality', 'dashboard'],
    queryFn: () => qualityApi.dashboard().then(r => r.data),
    refetchInterval: 60_000,
  });

  if (isLoading) return <SkeletonBlock lines={12} />;

  const d = data?.data;
  const stats = [
    { label: 'Open NCRs', value: d?.open_ncrs ?? 0, variant: (d?.open_ncrs > 5 ? 'danger' : 'warning') as any, href: '/quality/ncrs' },
    { label: 'Pending Inspections', value: d?.pending_inspections ?? 0, href: '/quality/inspections' },
    { label: 'Defect Rate', value: `${(d?.defect_rate ?? 0).toFixed(1)}%`, variant: (d?.defect_rate > 2 ? 'danger' : 'default') as any },
    { label: 'Inspections MTD', value: d?.inspections_mtd ?? 0 },
  ];

  return (
    <HubPage title="Quality Control" description="Inspections, non-conformances, and product traceability" stats={stats}>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <HubCard title="Open NCRs" icon={AlertOctagon} viewAllHref="/quality/ncrs">
          {d?.recent_ncrs?.length ? (
            <table className="w-full text-sm">
              <tbody>
                {d.recent_ncrs.slice(0, 5).map((ncr: any) => (
                  <tr key={ncr.id} className="border-b border-default last:border-0">
                    <td className="py-2">
                      <Link to={`/quality/ncrs/${ncr.id}`} className="text-accent hover:underline font-mono text-xs">{ncr.ncr_no}</Link>
                    </td>
                    <td className="py-2 text-sm">{ncr.title}</td>
                    <td className="py-2 text-right">
                      <Chip variant={ncr.severity === 'critical' ? 'danger' : ncr.severity === 'major' ? 'warning' : 'info'} size="sm">
                        {ncr.severity}
                      </Chip>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="text-sm text-success">No open NCRs</p>
          )}
        </HubCard>

        <HubCard title="Inspection Queue" icon={ClipboardCheck} viewAllHref="/quality/inspections">
          {d?.inspection_queue?.length ? (
            <table className="w-full text-sm">
              <tbody>
                {d.inspection_queue.slice(0, 5).map((insp: any) => (
                  <tr key={insp.id} className="border-b border-default last:border-0">
                    <td className="py-2">
                      <Chip variant={insp.type === 'incoming' ? 'info' : insp.type === 'in_process' ? 'warning' : 'success'} size="sm">
                        {insp.type}
                      </Chip>
                    </td>
                    <td className="py-2">{insp.product_name}</td>
                    <td className="py-2 text-right text-muted text-xs">{insp.scheduled_date}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="text-sm text-muted">No pending inspections</p>
          )}
        </HubCard>
      </div>

      <div className="mt-6">
        <h3 className="text-sm font-medium text-muted mb-3 uppercase tracking-wide">All Sections</h3>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
          <NavTile to="/quality/inspections" icon={ClipboardCheck} label="Inspections" desc="Record & review" />
          <NavTile to="/quality/ncrs" icon={AlertOctagon} label="NCRs" desc="Non-conformances" />
          <NavTile to="/quality/inspection-specs" icon={FileSearch} label="Specifications" desc="Dimension tolerances" />
          <NavTile to="/quality/traceability" icon={Link2} label="Traceability" desc="Lot & batch tracking" />
          <NavTile to="/quality/ncr-templates" icon={BookTemplate} label="NCR Templates" desc="Reusable templates" />
          <NavTile to="/quality/dashboard" icon={TrendingDown} label="Analytics" desc="Defect trends & Pareto" />
        </div>
      </div>
    </HubPage>
  );
}

function NavTile({ to, icon: Icon, label, desc }: { to: string; icon: any; label: string; desc: string }) {
  return (
    <Link to={to} className="rounded-md border border-default bg-surface p-3 hover:border-accent hover:shadow-sm transition-all group">
      <Icon size={18} className="text-muted group-hover:text-accent mb-2" />
      <p className="text-sm font-medium text-primary">{label}</p>
      <p className="text-xs text-muted mt-0.5">{desc}</p>
    </Link>
  );
}
```

- [ ] **Step 2: Update quality routes — change default redirect to hub**

In `qualityRoutes.tsx`, change the redirect from `/quality/dashboard` to `/quality/hub` and add hub route.

- [ ] **Step 3: Commit**

```bash
git add spa/src/pages/quality/hub.tsx spa/src/routes/qualityRoutes.tsx
git commit -m "feat: add Quality Hub page with NCR/inspection data and nav tiles"
```

---

### Task 5: Create Maintenance Hub page

**Files:**
- Create: `spa/src/pages/maintenance/hub.tsx`
- Modify: `spa/src/routes/maintenanceRoutes.tsx`

- [ ] **Step 1: Create maintenance hub**

Shows: open work orders, machine health summary (top 5 problematic), upcoming preventive schedules, links to assets/downtime analytics.

```typescript
// spa/src/pages/maintenance/hub.tsx
// Pattern identical to inventory/quality hubs
// Stats: Open WOs, Overdue WOs, Machines Down, Upcoming PM
// Cards: Active Work Orders table, Machine Health (top issues), Upcoming Schedules
// NavTiles: Work Orders, Schedules, Machine Health, Downtime Analytics, Assets
```

(Full implementation follows exact same HubPage/HubCard pattern as Task 3)

- [ ] **Step 2: Add route, commit**

---

### Task 6: Create CRM Hub page

**Files:**
- Create: `spa/src/pages/crm/hub.tsx`
- Modify: `spa/src/routes/crmRoutes.tsx`

- [ ] **Step 1: Create CRM hub**

Stats: Active Customers, Open Orders, Pending Complaints, Products
Cards: Recent Sales Orders, Open Complaints, Top Customers
NavTiles: Sales Orders, Products, Price Agreements, Complaints, Customers

- [ ] **Step 2: Add route, commit**

---

### Task 7: Create MRP Hub page

**Files:**
- Create: `spa/src/pages/mrp/hub.tsx`
- Modify: `spa/src/routes/mrpRoutes.tsx`

- [ ] **Step 1: Create MRP hub**

Stats: Active Plans, BOM Count, Machines, Mold Warnings
Cards: Active MRP Plans, Mold Shot Alerts (approaching max), Machine Utilization
NavTiles: Plans, BOMs, Machines, Molds

- [ ] **Step 2: Add route, commit**

---

### Task 8: Create Finance/Accounting Hub page

**Files:**
- Create: `spa/src/pages/accounting/hub.tsx`
- Modify: `spa/src/routes/accountingRoutes.tsx`

- [ ] **Step 1: Create finance hub**

Stats: AR Balance, AP Balance, Unposted JEs, Budget Utilization %
Cards: AR Aging Summary, AP Aging Summary, Recent Journal Entries
NavTiles: COA, Journal Entries, Invoices, Bills, Vendors, Customers, Trial Balance, Income Statement, Balance Sheet, Budgets

- [ ] **Step 2: Add route, commit**

---

### Task 9: Create Purchasing Hub page

**Files:**
- Create: `spa/src/pages/purchasing/hub.tsx`
- Modify: `spa/src/routes/purchasingRoutes.tsx`

- [ ] **Step 1: Create purchasing hub**

Stats: Pending PRs, Open POs, Approved Suppliers, Avg Lead Time
Cards: PR Pipeline (status breakdown), Recent POs, Supplier Performance
NavTiles: Purchase Requests, Purchase Orders, Approved Suppliers, PR Templates, Procurement Chain

- [ ] **Step 2: Add route, commit**

---

### Task 10: Create Supply Chain Hub page

**Files:**
- Create: `spa/src/pages/supply-chain/hub.tsx`
- Modify: `spa/src/routes/supplyChainRoutes.tsx`

- [ ] **Step 1: Create supply chain hub**

Stats: In-Transit, Delivered MTD, Fleet Vehicles, Avg Transit Time
Cards: Active Deliveries (tracking table), Shipment Status, Fleet Overview
NavTiles: Deliveries, Shipments, Fleet

- [ ] **Step 2: Add route, commit**

---

### Task 11: Create HR Hub page

**Files:**
- Create: `spa/src/pages/hr/hub.tsx`
- Modify: `spa/src/routes/hrRoutes.tsx`

- [ ] **Step 1: Create HR hub**

Stats: Total Employees, Active, On Leave, Separations In Progress
Cards: Department Headcount, Recent Profile Requests, Active Separations
NavTiles: Employees, Departments, Positions, Directory, Separations, Profile Requests, Loans

- [ ] **Step 2: Add route, commit**

---

### Task 12: Create Admin Hub page

**Files:**
- Create: `spa/src/pages/admin/hub.tsx`
- Modify: `spa/src/routes/adminRoutes.tsx`

- [ ] **Step 1: Create admin hub**

Stats: Active Users, Roles, Audit Events Today, Scheduled Exports
Cards: Recent Audit Activity, User Status Breakdown
NavTiles: Users, Roles, Audit Logs, Settings, Scheduled Exports, Gov Tables, Activity Feed

- [ ] **Step 2: Add route, commit**

---

### Task 13: Refactor Sidebar component

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

- [ ] **Step 1: Replace current navigation sections with new consolidated structure**

Reduce from 30+ items to ~22 primary items. All removed items must be accessible via their module hub page. Key changes:
- Remove: Calendar, Alerts, Demand Forecast, Loans, Assets (standalone), Shipments, Budgets
- Change: Quality → `/quality/hub`, Inventory Items → `/inventory/hub`, Maintenance → `/maintenance/hub`
- Add: Notifications item with unread badge
- Keep: All items that are primary workflows (create/process things)

- [ ] **Step 2: Update sidebar badge keys**

Add new badges:
- `pending_so` for Sales Orders (open/pending confirmation)
- `unread` for Notifications (unread notification count)

Combine overtime + leaves into single attendance badge showing sum.

- [ ] **Step 3: Verify all sidebar links point to valid routes**

```bash
cd spa && npx tsc --noEmit
```

- [ ] **Step 4: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat: consolidate sidebar — reduce to essential links, point to hub pages"
```

---

### Task 14: Add missing badge endpoints (backend)

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/BadgeService.php`

- [ ] **Step 1: Add notification unread count badge**

```php
// In counters() method:
if ($user->can('notifications.view')) {
    $badges['unread'] = [
        'count' => $user->unreadNotifications()->count(),
        'severity' => $this->severity($user->unreadNotifications()->count()),
    ];
}
```

- [ ] **Step 2: Add pending sales orders badge**

```php
if ($user->can('crm.sales_orders.view')) {
    $count = \App\Modules\CRM\Models\SalesOrder::whereIn('status', ['draft', 'pending_confirmation'])->count();
    $badges['pending_so'] = [
        'count' => $count,
        'severity' => $this->severity($count),
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add api/app/Modules/Dashboard/Services/BadgeService.php
git commit -m "feat: add notification unread and pending SO badge counts"
```

---

### Task 15: Add hub API endpoints (backend)

**Files:**
- Create: `api/app/Modules/Inventory/Controllers/InventoryHubController.php`
- Create: `api/app/Modules/Quality/Controllers/QualityHubController.php`
- Create: `api/app/Modules/Maintenance/Controllers/MaintenanceHubController.php`
- Modify: relevant `routes.php` files

Some hubs can reuse existing dashboard endpoints. Others need dedicated hub data endpoints that aggregate stats differently (optimized for the hub card layout).

- [ ] **Step 1: Create hub controllers that return aggregated data for each hub page**

Each returns: `{ stats: {...}, cards: { recent_items: [...], alerts: [...] } }`

- [ ] **Step 2: Register routes**

- [ ] **Step 3: Commit**

```bash
git add api/app/Modules/*/Controllers/*HubController.php api/app/Modules/*/routes.php
git commit -m "feat: add hub data endpoints for inventory, quality, maintenance"
```

---

## Phase 2: Professional Role Dashboards with Charts

### Problem Statement

Current dashboards are table-only (no chart library). They show data but lack:
- Trend lines (how is this KPI changing?)
- Forecasting visualizations
- Comparative charts (budget vs actual, this month vs last)
- Professional analytics feel

Standalone analytics pages (Demand Forecast, Stock-out Projection, OEE Report, Downtime Analytics, Budget vs Actual) should be absorbed INTO the relevant role dashboard as embedded sections.

### Dashboard Enhancement Strategy

Each role dashboard gets:
1. **KPI row** (existing) — enhanced with sparkline trend indicators
2. **Charts section** — 2-3 Recharts visualizations (line, bar, area)
3. **Embedded analytics** — formerly standalone pages become dashboard sections
4. **Action items** — what needs attention NOW
5. **Forecast section** — relevant predictive data

### Pages Being Absorbed Into Dashboards

| Standalone Page | Absorbed Into |
|----------------|---------------|
| `/forecasting/demand` | PPC Dashboard + Plant Manager Dashboard |
| `/forecasting/stock-out` | Warehouse Dashboard + Purchasing Dashboard |
| `/production/oee` | PPC Dashboard + Plant Manager Dashboard |
| `/production/dashboard` | PPC Dashboard (merge) |
| `/maintenance/downtime` | Plant Manager Dashboard (summary) |
| `/maintenance/machine-health` | PPC Dashboard (summary) |
| `/budgeting/budget-vs-actual` | Finance Dashboard |
| `/inventory/dashboard` | Warehouse Dashboard (merge) |

**Note:** These pages remain accessible via their hub pages for detailed drill-down. The dashboard shows summary/preview with "View Details →" links.

---

### Task 16: Create chart wrapper components

**Files:**
- Create: `spa/src/components/charts/AreaTrend.tsx`
- Create: `spa/src/components/charts/BarComparison.tsx`
- Create: `spa/src/components/charts/DonutBreakdown.tsx`
- Create: `spa/src/components/charts/SparkLine.tsx`

- [ ] **Step 1: Create AreaTrend (time-series line/area chart)**

```typescript
// spa/src/components/charts/AreaTrend.tsx
import { AreaChart, Area, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid } from 'recharts';

interface AreaTrendProps {
  data: Array<{ period: string; value: number; forecast?: number }>;
  dataKey?: string;
  color?: string;
  height?: number;
  showForecast?: boolean;
  unit?: string;
  formatValue?: (v: number) => string;
}

export function AreaTrend({ data, dataKey = 'value', color = 'var(--color-accent)', height = 200, showForecast, unit = '', formatValue }: AreaTrendProps) {
  const fmt = formatValue || ((v: number) => `${v.toLocaleString()}${unit}`);

  return (
    <ResponsiveContainer width="100%" height={height}>
      <AreaChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
        <XAxis dataKey="period" tick={{ fontSize: 11, fill: 'var(--color-muted)' }} axisLine={false} tickLine={false} />
        <YAxis tick={{ fontSize: 11, fill: 'var(--color-muted)' }} axisLine={false} tickLine={false} tickFormatter={fmt} />
        <Tooltip
          contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 6, fontSize: 12 }}
          formatter={(v: number) => [fmt(v), '']}
        />
        <Area type="monotone" dataKey={dataKey} stroke={color} fill={color} fillOpacity={0.1} strokeWidth={2} />
        {showForecast && (
          <Area type="monotone" dataKey="forecast" stroke={color} fill={color} fillOpacity={0.05} strokeWidth={2} strokeDasharray="5 5" />
        )}
      </AreaChart>
    </ResponsiveContainer>
  );
}
```

- [ ] **Step 2: Create BarComparison (grouped/stacked bar)**

```typescript
// spa/src/components/charts/BarComparison.tsx
import { BarChart, Bar, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend } from 'recharts';

interface BarComparisonProps {
  data: Array<Record<string, any>>;
  bars: Array<{ dataKey: string; color: string; label: string }>;
  xKey?: string;
  height?: number;
  stacked?: boolean;
}

export function BarComparison({ data, bars, xKey = 'label', height = 200, stacked }: BarComparisonProps) {
  return (
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
        <XAxis dataKey={xKey} tick={{ fontSize: 11, fill: 'var(--color-muted)' }} axisLine={false} tickLine={false} />
        <YAxis tick={{ fontSize: 11, fill: 'var(--color-muted)' }} axisLine={false} tickLine={false} />
        <Tooltip contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 6, fontSize: 12 }} />
        <Legend wrapperStyle={{ fontSize: 11 }} />
        {bars.map((bar) => (
          <Bar key={bar.dataKey} dataKey={bar.dataKey} fill={bar.color} name={bar.label} stackId={stacked ? 'stack' : undefined} radius={[2, 2, 0, 0]} />
        ))}
      </BarChart>
    </ResponsiveContainer>
  );
}
```

- [ ] **Step 3: Create DonutBreakdown (pie/donut chart)**

```typescript
// spa/src/components/charts/DonutBreakdown.tsx
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from 'recharts';

interface DonutBreakdownProps {
  data: Array<{ name: string; value: number; color: string }>;
  height?: number;
  innerRadius?: number;
  centerLabel?: string;
  centerValue?: string;
}

export function DonutBreakdown({ data, height = 180, innerRadius = 50, centerLabel, centerValue }: DonutBreakdownProps) {
  return (
    <div className="relative">
      <ResponsiveContainer width="100%" height={height}>
        <PieChart>
          <Pie data={data} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={innerRadius} outerRadius={innerRadius + 25} paddingAngle={2}>
            {data.map((entry, i) => (
              <Cell key={i} fill={entry.color} />
            ))}
          </Pie>
          <Tooltip contentStyle={{ background: 'var(--color-surface)', border: '1px solid var(--color-border)', borderRadius: 6, fontSize: 12 }} />
        </PieChart>
      </ResponsiveContainer>
      {centerLabel && (
        <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
          <span className="text-2xl font-semibold font-mono tabular-nums">{centerValue}</span>
          <span className="text-xs text-muted">{centerLabel}</span>
        </div>
      )}
      <div className="flex flex-wrap gap-3 justify-center mt-2">
        {data.map((entry) => (
          <div key={entry.name} className="flex items-center gap-1.5 text-xs">
            <span className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: entry.color }} />
            <span className="text-muted">{entry.name}</span>
            <span className="font-mono tabular-nums">{entry.value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Create SparkLine (inline mini trend)**

```typescript
// spa/src/components/charts/SparkLine.tsx
import { LineChart, Line, ResponsiveContainer } from 'recharts';

interface SparkLineProps {
  data: number[];
  color?: string;
  height?: number;
  width?: number;
}

export function SparkLine({ data, color = 'var(--color-accent)', height = 24, width = 80 }: SparkLineProps) {
  const chartData = data.map((v, i) => ({ i, v }));
  return (
    <ResponsiveContainer width={width} height={height}>
      <LineChart data={chartData}>
        <Line type="monotone" dataKey="v" stroke={color} strokeWidth={1.5} dot={false} />
      </LineChart>
    </ResponsiveContainer>
  );
}
```

- [ ] **Step 5: Commit**

```bash
git add spa/src/components/charts/
git commit -m "feat: add Recharts wrapper components — AreaTrend, BarComparison, DonutBreakdown, SparkLine"
```

---

### Task 17: Enhanced StatCard with trend sparkline

**Files:**
- Modify: `spa/src/components/ui/StatCard.tsx`

- [ ] **Step 1: Add optional `trend` prop to StatCard**

```typescript
interface StatCardProps {
  label: string;
  value: string | number;
  unit?: string;
  variant?: 'default' | 'success' | 'warning' | 'danger';
  trend?: { data: number[]; direction: 'up' | 'down' | 'flat'; percentage?: string };
  href?: string;
}
```

Add SparkLine below the value when `trend` is provided, plus a small arrow indicator and percentage.

- [ ] **Step 2: Commit**

```bash
git add spa/src/components/ui/StatCard.tsx
git commit -m "feat: enhance StatCard with trend sparkline and direction indicator"
```

---

### Task 18: Upgrade Plant Manager Dashboard

**Files:**
- Modify: `spa/src/pages/dashboard/plant-manager.tsx`

- [ ] **Step 1: Add Recharts to Plant Manager dashboard**

Replace the CSS progress bars with proper charts:
- **Production Output Trend** (AreaTrend) — daily output last 30 days with forecast line
- **Defect Pareto** (BarComparison) — horizontal bar chart of defect categories
- **Machine Utilization** (DonutBreakdown) — running vs idle vs down
- **OEE Trend** (AreaTrend) — absorbed from standalone OEE page
- **Demand Forecast** (AreaTrend with forecast) — absorbed from standalone forecasting page
- **KPI sparklines** on all stat cards

- [ ] **Step 2: Add backend endpoint for plant manager trends**

Modify `PlantManagerDashboardService` to return time-series data in addition to current KPIs:
```php
// Add to response:
'trends' => [
    'output' => [...30 days of {period, value}...],
    'oee' => [...30 days of {period, value, target}...],
    'defect_rate' => [...30 days of {period, value}...],
],
'forecast' => [
    'demand' => [...next 12 weeks...],
],
```

- [ ] **Step 3: Commit**

```bash
git add spa/src/pages/dashboard/plant-manager.tsx api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php
git commit -m "feat: upgrade Plant Manager dashboard with Recharts trends and embedded forecasting"
```

---

### Task 19: Upgrade HR Dashboard

**Files:**
- Modify: `spa/src/pages/dashboard/hr.tsx`

- [ ] **Step 1: Add charts to HR dashboard**

- **Headcount Trend** (AreaTrend) — monthly headcount last 12 months
- **Attendance Rate** (AreaTrend) — daily attendance % last 30 days
- **Department Distribution** (DonutBreakdown) — employees per department
- **Leave Utilization** (BarComparison) — leave used vs available by type
- **Turnover Forecast** (AreaTrend with forecast) — projected separations
- **KPI sparklines** on all stat cards

- [ ] **Step 2: Update HrDashboardService for trends**

- [ ] **Step 3: Commit**

---

### Task 20: Upgrade Finance Dashboard

**Files:**
- Modify: `spa/src/pages/dashboard/finance.tsx`

- [ ] **Step 1: Add charts to Finance dashboard**

- **Cash Flow Trend** (AreaTrend) — AR collections vs AP payments last 6 months
- **AR/AP Aging** (BarComparison) — stacked bar by aging bucket (current, 30, 60, 90+)
- **Budget vs Actual** (BarComparison) — absorbed from standalone page, grouped bar by department
- **Revenue Trend** (AreaTrend with forecast) — monthly revenue with projection
- **Expense Breakdown** (DonutBreakdown) — by category
- **KPI sparklines** on stat cards

- [ ] **Step 2: Update FinanceDashboardService**

- [ ] **Step 3: Commit**

---

### Task 21: Upgrade PPC Dashboard

**Files:**
- Modify: `spa/src/pages/dashboard/ppc.tsx`

- [ ] **Step 1: Add charts to PPC dashboard**

- **Production Output** (BarComparison) — planned vs actual by day
- **OEE Trend** (AreaTrend) — absorbed OEE report mini-version
- **Machine Utilization** (DonutBreakdown) — running/idle/down breakdown
- **MRP Material Availability** (BarComparison) — shortages by material
- **Demand Forecast** (AreaTrend with forecast) — absorbed forecasting page
- **WO Completion Rate** (AreaTrend) — daily trend
- **Gantt Mini** — keep existing but style better

- [ ] **Step 2: Update PpcDashboardService**

- [ ] **Step 3: Commit**

---

### Task 22: Upgrade Purchasing Dashboard

**Files:**
- Modify: `spa/src/pages/dashboard/purchasing.tsx`

- [ ] **Step 1: Add charts**

- **PO Spend Trend** (AreaTrend) — monthly procurement spend
- **Supplier Lead Time** (BarComparison) — average by top suppliers
- **PR-to-PO Cycle Time** (AreaTrend) — days from PR creation to PO approval
- **Stock-out Projection** (AreaTrend with forecast) — absorbed from standalone
- **Procurement Status** (DonutBreakdown) — PO status breakdown

- [ ] **Step 2: Update PurchasingDashboardService**

- [ ] **Step 3: Commit**

---

### Task 23: Upgrade Warehouse Dashboard

**Files:**
- Modify: `spa/src/pages/dashboard/warehouse.tsx`

- [ ] **Step 1: Add charts**

- **Stock Turnover** (AreaTrend) — monthly turnover ratio
- **Receiving vs Issuing** (BarComparison) — daily volume comparison
- **Zone Utilization** (DonutBreakdown) — capacity by zone
- **Stock-out Forecast** (AreaTrend with forecast) — absorbed standalone
- **Low Stock Trend** (AreaTrend) — items below reorder over time

- [ ] **Step 2: Update WarehouseDashboardService**

- [ ] **Step 3: Commit**

---

### Task 24: Upgrade Quality Dashboard

**Files:**
- Modify: `spa/src/pages/dashboard/quality.tsx`

- [ ] **Step 1: Add charts**

- **Defect Rate Trend** (AreaTrend) — monthly defect rate with target line
- **Defect Pareto** (BarComparison) — top defect categories (keep existing data, use chart)
- **NCR Resolution Time** (BarComparison) — avg days to close by severity
- **Inspection Pass Rate** (AreaTrend) — monthly pass/fail trend
- **QC Coverage** (DonutBreakdown) — inspected vs uninspected lots

- [ ] **Step 2: Update QualityDashboardService**

- [ ] **Step 3: Commit**

---

### Task 25: Backend trend data endpoints

**Files:**
- Modify: `api/app/Modules/Dashboard/Services/PlantManagerDashboardService.php`
- Modify: `api/app/Modules/Dashboard/Services/HrDashboardService.php`
- Modify: `api/app/Modules/Dashboard/Services/FinanceDashboardService.php`
- Modify: `api/app/Modules/Dashboard/Services/PpcDashboardService.php`
- Modify: `api/app/Modules/Dashboard/Services/PurchasingDashboardService.php`
- Modify: `api/app/Modules/Dashboard/Services/WarehouseDashboardService.php`
- Modify: `api/app/Modules/Dashboard/Services/QualityDashboardService.php`

Each service needs to return time-series data arrays for their charts. Pattern:

```php
'trends' => [
    'key_name' => collect(range(29, 0))->map(fn($daysAgo) => [
        'period' => now()->subDays($daysAgo)->format('M d'),
        'value' => $this->computeMetricForDate(now()->subDays($daysAgo)),
    ])->values()->all(),
],
'forecast' => [
    'key_name' => collect(range(1, 12))->map(fn($weeksAhead) => [
        'period' => now()->addWeeks($weeksAhead)->format('M d'),
        'forecast' => $this->projectMetric($weeksAhead),
    ])->values()->all(),
],
```

- [ ] **Step 1: Add trend methods to each dashboard service**

- [ ] **Step 2: Cache trend data (5-minute TTL — trends don't change fast)**

```php
return Cache::remember("dashboard.{$role}.trends", 300, fn() => $this->computeTrends());
```

- [ ] **Step 3: Commit**

```bash
git add api/app/Modules/Dashboard/Services/
git commit -m "feat: add time-series trend and forecast data to all role dashboard services"
```

---

### Task 26: Remove standalone analytics route entries from sidebar (cleanup)

**Files:**
- Modify: `spa/src/routes/advancedRoutes.tsx` (keep routes but remove from sidebar consideration)
- Modify: `spa/src/components/layout/Sidebar.tsx` (already done in Task 13, verify)

The standalone pages REMAIN as routes (for deep-link bookmarks and hub page navigation) but are NOT in the sidebar anymore. They're accessed via:
- Hub pages (NavTile links)
- Dashboard "View Details →" links

- [ ] **Step 1: Verify all formerly-standalone pages have at least one navigation path**

- [ ] **Step 2: Commit if any route cleanup needed**

---

## Phase 3: Dynamic RBAC Administration

### Problem Statement

Current RBAC admin pages exist (Roles, Permissions, Users) but need:
1. **Permission Matrix view** — visual grid of roles × permissions with toggle
2. **Live permission search** — find which roles have a specific permission
3. **Role comparison** (exists) — enhance with side-by-side diff
4. **Permission override management** — better UX for per-user exceptions
5. **Permission groups** — collapsible by module
6. **Frontend execution** — CanDo component works, but need dynamic permission refresh and real-time sync

---

### Task 27: Enhanced Role Permissions page with matrix view

**Files:**
- Modify: `spa/src/pages/admin/roles/permissions.tsx`

- [ ] **Step 1: Add permission matrix grid view**

Create a toggleable view (list vs matrix). Matrix shows:
- Rows = permission groups (module.resource)
- Columns = actions (view, create, edit, delete, approve, manage)
- Cells = checkboxes bound to role's permissions
- Collapsible by module section
- Search/filter bar at top

```typescript
// Key structure:
interface PermissionMatrix {
  modules: Array<{
    name: string;
    resources: Array<{
      name: string;
      permissions: Array<{
        slug: string;
        action: string;
        granted: boolean;
      }>;
    }>;
  }>;
}
```

- [ ] **Step 2: Add bulk toggle (enable all view, enable all for module)**

- [ ] **Step 3: Add unsaved changes indicator and save button**

- [ ] **Step 4: Commit**

```bash
git add spa/src/pages/admin/roles/permissions.tsx
git commit -m "feat: add permission matrix grid view with search and bulk toggle"
```

---

### Task 28: Permission search & audit

**Files:**
- Create: `spa/src/pages/admin/roles/permission-search.tsx`
- Modify: `spa/src/routes/adminRoutes.tsx`

- [ ] **Step 1: Create permission search page**

Features:
- Search input: type a permission slug (e.g., "inventory.view")
- Shows: which roles have it, which users have it (via role or override)
- Reverse lookup: select a user → see all their effective permissions
- Export capability

- [ ] **Step 2: Add backend endpoint**

```php
// GET /api/v1/admin/permissions/search?q=inventory.view
// Returns: { roles: [...], users: [...], overrides: [...] }
```

- [ ] **Step 3: Add route and link from admin hub**

- [ ] **Step 4: Commit**

---

### Task 29: User permission overrides UX improvement

**Files:**
- Modify: `spa/src/pages/admin/users/_components/PermissionOverrides.tsx`

- [ ] **Step 1: Enhance the override interface**

Current: simple add/remove. Enhanced:
- Show effective permissions (role + overrides) in unified view
- Visual diff: green = granted by override, red = revoked by override, gray = from role
- Quick-add with permission search autocomplete
- Reason field for each override (audit trail)
- Expiry date option (temporary overrides)

- [ ] **Step 2: Commit**

---

### Task 30: Real-time permission refresh on frontend

**Files:**
- Modify: `spa/src/stores/authStore.ts`
- Modify: `spa/src/hooks/usePermission.ts`

- [ ] **Step 1: Add WebSocket listener for permission changes**

When admin changes a user's permissions, broadcast event. Frontend listens and refreshes auth state:

```typescript
// In authStore initialization:
window.Echo?.private(`user.${user.id}`)
  .listen('.PermissionsChanged', () => {
    refreshAuth(); // re-fetches /api/v1/auth/me with fresh permissions
  });
```

- [ ] **Step 2: Add backend broadcast on permission change**

```php
// In RoleController::syncPermissions() and UserOverrideController::store/destroy:
broadcast(new PermissionsChanged($affectedUserIds));
```

- [ ] **Step 3: Toast notification to user when permissions change**

```typescript
// In the listener:
toast.info('Your permissions have been updated. Some features may have changed.');
```

- [ ] **Step 4: Commit**

```bash
git add spa/src/stores/authStore.ts spa/src/hooks/usePermission.ts api/app/Events/PermissionsChanged.php
git commit -m "feat: real-time permission sync via WebSocket broadcast"
```

---

### Task 31: Module feature toggle immediate effect

**Files:**
- Modify: `spa/src/pages/admin/settings.tsx`
- Verify: `spa/src/components/guards/ModuleGuard.tsx`

- [ ] **Step 1: When admin toggles a module, broadcast to all connected users**

Currently `refreshAuth()` is called locally for the admin. Need to broadcast to ALL users so their sidebars and module guards update immediately.

```php
// SettingsController::update when key starts with 'modules.':
broadcast(new ModuleToggled($moduleKey, $enabled))->toOthers();
```

- [ ] **Step 2: Frontend listener invalidates settings cache**

```typescript
window.Echo?.channel('settings')
  .listen('.ModuleToggled', () => {
    queryClient.invalidateQueries({ queryKey: ['settings'] });
    refreshAuth();
  });
```

- [ ] **Step 3: Commit**

---

## Phase 4: Additional Quality Improvements (Audit Findings)

### Task 32: Self-service hub enhancement

**Files:**
- Modify: `spa/src/pages/self-service/index.tsx`

Current self-service home is likely a list of links. Enhance to show actual data:
- My DTR summary (present/late/absent this month)
- Leave balance cards
- Active loan & next deduction
- Recent payslips
- Pending requests (leave, OT, profile update)

- [ ] **Step 1: Add data fetching and stat cards to self-service home**

- [ ] **Step 2: Commit**

---

### Task 33: Breadcrumb navigation for deep pages

**Files:**
- Create: `spa/src/components/layout/Breadcrumb.tsx`
- Modify: detail pages (employee detail, WO detail, etc.)

- [ ] **Step 1: Create Breadcrumb component**

```typescript
interface BreadcrumbProps {
  items: Array<{ label: string; href?: string }>;
}
```

Auto-generates from URL path but allows override. Shows module → hub → current page.

- [ ] **Step 2: Add to all detail pages that are 3+ levels deep**

- [ ] **Step 3: Commit**

---

### Task 34: "Back to Hub" pattern for sub-pages

**Files:**
- Add to all sub-pages that are inside a hub's domain

- [ ] **Step 1: Add consistent back navigation**

Every page that lives under a hub should have a breadcrumb or back link to its hub. Example: `/inventory/warehouse-map` shows "← Inventory Hub" at top.

Pattern:
```typescript
<div className="mb-4">
  <Link to="/inventory/hub" className="text-sm text-accent hover:underline flex items-center gap-1">
    <ArrowLeft size={14} /> Inventory Hub
  </Link>
</div>
```

- [ ] **Step 2: Commit**

---

### Task 35: Sidebar collapsed state — tooltip with badge

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

- [ ] **Step 1: When sidebar is collapsed (rail mode), show badge dot on icon**

Currently badges disappear in collapsed mode. Fix: show a small colored dot (danger/warning) on the icon corner when collapsed, with tooltip showing full count on hover.

- [ ] **Step 2: Commit**

---

### Task 36: Global command palette / quick nav

**Files:**
- Create: `spa/src/components/layout/CommandPalette.tsx`
- Modify: `spa/src/components/layout/AppLayout.tsx`

- [ ] **Step 1: Create command palette (Cmd+K / Ctrl+K)**

Since we're hiding pages behind hubs, users need fast access. Command palette:
- Fuzzy search all pages by name
- Recent pages (last 5 visited)
- Quick actions (create PO, create WO, etc.)
- Opens with keyboard shortcut

```typescript
// Key structure:
const ALL_PAGES = [
  { label: 'Inventory Hub', path: '/inventory/hub', keywords: ['stock', 'warehouse', 'items'] },
  { label: 'Create Purchase Order', path: '/purchasing/purchase-orders/create', keywords: ['PO', 'buy'] },
  // ... all pages
];
```

- [ ] **Step 2: Register Cmd+K handler in AppLayout**

- [ ] **Step 3: Commit**

```bash
git add spa/src/components/layout/CommandPalette.tsx spa/src/components/layout/AppLayout.tsx
git commit -m "feat: add Cmd+K command palette for quick page navigation"
```

---

### Task 37: Return Management & Forecasting accessible from hubs

**Files:**
- Modify: Supply Chain Hub (Task 10) — add Return Management NavTile
- Modify: CRM Hub (Task 6) — add Return Management NavTile
- Modify: Purchasing Hub (Task 9) — add Forecasting NavTile

- [ ] **Step 1: Ensure return management is navigable from Supply Chain hub and CRM hub**

- [ ] **Step 2: Ensure forecasting pages are navigable from relevant hubs (Purchasing, PPC areas)**

- [ ] **Step 3: Commit**

---

### Task 38: Remove dead default dashboard widget system

**Files:**
- Modify: `spa/src/pages/dashboard/default.tsx`

The default dashboard (for roles without a specific dashboard) currently uses a widget system. Since every role now gets a proper dashboard, the default should:
- Show a summary of cross-module alerts
- Show user's recent activity
- Show their approval queue
- Use the same chart components for consistency

- [ ] **Step 1: Rebuild default dashboard as a clean summary page**

- [ ] **Step 2: Commit**

---

### Task 39: Verify zero orphaned pages (final audit)

**Files:**
- No new files. Verification task.

- [ ] **Step 1: Script to find all route paths and verify each has at least one link pointing to it**

```bash
# Extract all route paths from route files
grep -r "path:" spa/src/routes/ | grep -oP "path:\s*'[^']+'" | sort

# Extract all Link to= and NavTile to= in pages and components
grep -rn "to=\"/" spa/src/ | grep -oP 'to="[^"]+"' | sort | uniq
```

- [ ] **Step 2: Cross-reference — any route path with zero inbound links = orphan**

- [ ] **Step 3: Fix any remaining orphans by adding them to relevant hub NavTile grids**

- [ ] **Step 4: Commit**

---

### Task 40: Final typecheck and build verification

**Files:**
- No new files.

- [ ] **Step 1: Run full typecheck**

```bash
cd spa && npx tsc --noEmit
```

- [ ] **Step 2: Run full build**

```bash
cd spa && npm run build
```

- [ ] **Step 3: Fix any errors**

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore: fix typecheck errors from UX overhaul"
```

---

## Summary of Changes

| Area | Before | After |
|------|--------|-------|
| Sidebar items | 30+ across 11 sections | ~22 essential links |
| Hub pages | 3 (Payroll, Attendance, Users/Roles) | 13 (every module has one) |
| Orphaned pages | 40+ unreachable | 0 — all navigable via hubs |
| Dashboard charts | None (table/CSS only) | Recharts: area, bar, donut, sparkline |
| Standalone analytics | 8+ separate pages | Absorbed into role dashboards |
| Badge count items | 11 | 13 (added notifications, pending SO) |
| RBAC admin | Basic list/edit | Permission matrix, search, real-time sync |
| Navigation fallback | None | Cmd+K command palette |
| Breadcrumbs | None | On all deep pages |
| Collapsed sidebar badges | Hidden | Colored dot indicator |

---

## Execution Order & Dependencies

```
Phase 1 (Sidebar + Hubs): Tasks 1-15
  ├── Task 1 (recharts) — no deps
  ├── Task 2 (HubPage/HubCard components) — no deps
  ├── Tasks 3-12 (hub pages) — depend on Task 2
  ├── Task 13 (sidebar refactor) — depends on Tasks 3-12 (hubs exist first)
  ├── Task 14 (badge endpoints) — no deps (backend)
  └── Task 15 (hub API endpoints) — no deps (backend), Tasks 3-12 need this

Phase 2 (Dashboards): Tasks 16-26
  ├── Task 16 (chart components) — depends on Task 1
  ├── Task 17 (StatCard enhancement) — depends on Task 16
  ├── Tasks 18-24 (dashboard upgrades) — depend on Task 16, 17
  ├── Task 25 (backend trends) — no deps (backend)
  └── Task 26 (cleanup verification) — depends on Task 13

Phase 3 (RBAC): Tasks 27-31
  ├── Task 27 (permission matrix) — no deps
  ├── Task 28 (permission search) — no deps
  ├── Task 29 (overrides UX) — no deps
  ├── Task 30 (real-time sync) — no deps
  └── Task 31 (module toggle broadcast) — no deps

Phase 4 (Polish): Tasks 32-40
  ├── Task 32 (self-service) — no deps
  ├── Task 33 (breadcrumbs) — no deps
  ├── Task 34 (back-to-hub) — depends on hubs existing (Phase 1)
  ├── Task 35 (collapsed badges) — no deps
  ├── Task 36 (command palette) — no deps
  ├── Task 37 (return mgmt nav) — depends on hubs (Phase 1)
  ├── Task 38 (default dashboard) — depends on chart components (Phase 2)
  ├── Task 39 (orphan audit) — depends on all above
  └── Task 40 (final build) — last
```

**Parallelizable groups:**
- Backend tasks (14, 15, 25, 28 backend, 30 backend, 31 backend) can run in parallel with frontend
- All hub pages (Tasks 3-12) are independent of each other
- All dashboard upgrades (Tasks 18-24) are independent of each other
- All RBAC tasks (27-31) are independent of each other
