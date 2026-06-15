# Frontend Audit Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all frontend audit findings — 3 missing sidebar module sections, 40+ missing sidebar nav items across existing sections, 5 missing page/route pairs, 1 orphaned file, and edit/delete buttons on BOM/Asset/Schedule detail pages.

**Architecture:** Pure frontend changes. No new backend endpoints needed — all API methods already exist. Sidebar changes are in `spa/src/components/layout/Sidebar.tsx` (SECTIONS array + icon imports). New pages follow the existing create page pattern (React Hook Form + Zod + TanStack Query). New routes add lazy imports + `<Route>` entries to the module's `*Routes.tsx` file.

**Tech Stack:** React 18 + TypeScript, TanStack Query, React Hook Form + Zod, Lucide Icons, Tailwind CSS, react-hot-toast

---

## File Map

| Action | File |
|--------|------|
| Modify | `spa/src/components/layout/Sidebar.tsx` (Tasks 1–6) |
| Modify | `spa/src/routes/accountingRoutes.tsx` (Task 7) |
| Create | `spa/src/pages/accounting/coa/create.tsx` (Task 7) |
| Create | `spa/src/pages/accounting/coa/edit.tsx` (Task 7) |
| Modify | `spa/src/pages/accounting/coa/index.tsx` (Task 7) |
| Modify | `spa/src/routes/inventoryRoutes.tsx` (Task 8) |
| Create | `spa/src/pages/inventory/material-issues/detail.tsx` (Task 8) |
| Modify | `spa/src/routes/mrpRoutes.tsx` (Task 9) |
| Create | `spa/src/pages/mrp/boms/edit.tsx` (Task 9) |
| Modify | `spa/src/pages/mrp/boms/detail.tsx` (Task 9) |
| Modify | `spa/src/routes/assetsRoutes.tsx` (Task 10) |
| Create | `spa/src/pages/assets/edit.tsx` (Task 10) |
| Modify | `spa/src/pages/assets/detail.tsx` (Task 10) |
| Modify | `spa/src/routes/maintenanceRoutes.tsx` (Task 11) |
| Create | `spa/src/pages/maintenance/schedules/detail.tsx` (Task 11) |
| Create | `spa/src/pages/maintenance/schedules/edit.tsx` (Task 11) |
| Modify | `spa/src/pages/maintenance/schedules/index.tsx` (Task 11) |
| Delete | `spa/src/pages/inventory/receive.tsx` (Task 12) |

---

## Task 1: Sidebar — Add three entirely missing module sections

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

The sidebar has zero entries for Assets, Forecasting, and Return Management. Routes exist, pages exist, but users have no way to navigate to them.

- [ ] **Step 1: Add new icon imports to Sidebar.tsx**

Open `spa/src/components/layout/Sidebar.tsx`. The current import from `lucide-react` ends at `ArrowLeftRight`. Add to that import block:

```typescript
import {
  // ... existing imports unchanged ...
  ArrowLeftRight,
  TrendingUp,
  RotateCcw,
  Building2,
  type LucideIcon,
} from 'lucide-react';
```

- [ ] **Step 2: Add the three new sections to SECTIONS array**

In `Sidebar.tsx`, locate the `SECTIONS` array. After the `Maintenance` section object (the one with `work-orders` and `schedules`) and before the `Administration` section, insert:

```typescript
  {
    label: 'Assets',
    items: [
      { to: '/assets', label: 'Fixed Assets', icon: Building2, feature: 'assets', permission: 'assets.view' },
    ],
  },
  {
    label: 'Forecasting',
    items: [
      { to: '/forecasting/demand',    label: 'Demand Forecast',    icon: TrendingUp,   feature: 'forecasting', permission: 'forecasting.view' },
      { to: '/forecasting/stock-out', label: 'Stock-Out Projection', icon: AlertTriangle, feature: 'forecasting', permission: 'forecasting.view' },
    ],
  },
  {
    label: 'Return Management',
    items: [
      { to: '/return-management', label: 'Returns (RMA)', icon: RotateCcw, feature: 'return_management', permission: 'return_management.view' },
    ],
  },
```

- [ ] **Step 3: Verify in browser**

Navigate to each new section link. Confirm `/assets`, `/forecasting/demand`, `/forecasting/stock-out`, `/return-management` all load without 404. The sidebar active-highlight should track the current route.

- [ ] **Step 4: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat: sidebar — add Assets, Forecasting, Return Management sections"
```

---

## Task 2: Sidebar — Expand Finance section

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

Finance currently shows only: Journal Entries, Invoices (AR), Bills (AP), Budget Transfers. COA, Vendors, all three financial statements, and the Budgets overview are unreachable from the sidebar.

- [ ] **Step 1: Add more icon imports**

Add to the lucide-react import in `Sidebar.tsx`:

```typescript
  BarChart2,
  PieChart,
  Landmark,
  Store,
```

- [ ] **Step 2: Replace the Finance section in SECTIONS**

Find the existing Finance section and replace it entirely:

```typescript
  {
    label: 'Finance',
    items: [
      { to: '/accounting/coa',              label: 'Chart of Accounts', icon: Landmark,       feature: 'accounting', permission: 'accounting.coa.view' },
      { to: '/accounting/journal-entries',  label: 'Journal Entries',   icon: BookOpen,       feature: 'accounting', permission: 'accounting.journal.view' },
      { to: '/accounting/invoices',         label: 'Invoices (AR)',     icon: FileText,       feature: 'accounting', permission: 'accounting.invoices.view' },
      { to: '/accounting/bills',            label: 'Bills (AP)',        icon: Receipt,        feature: 'accounting', permission: 'accounting.bills.view' },
      { to: '/accounting/vendors',          label: 'Vendors',           icon: Store,          feature: 'accounting', permission: 'accounting.vendors.view' },
      { to: '/accounting/trial-balance',    label: 'Trial Balance',     icon: BarChart2,      feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/accounting/income-statement', label: 'Income Statement',  icon: TrendingUp,     feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/accounting/balance-sheet',    label: 'Balance Sheet',     icon: BarChart2,      feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/budgeting',                   label: 'Budgets',           icon: PieChart,       permission: 'budgeting.view' },
      { to: '/budgeting/budget-vs-actual',  label: 'Budget vs Actual',  icon: BarChart2,      permission: 'budgeting.view' },
      { to: '/budgeting/transfers',         label: 'Budget Transfers',  icon: ArrowLeftRight, feature: 'accounting', permission: 'accounting.budget.view' },
    ],
  },
```

Note: `TrendingUp` and `ArrowLeftRight` are already in scope from previous steps.

- [ ] **Step 3: Verify in browser**

Navigate to `/accounting/coa`, `/accounting/vendors`, `/accounting/trial-balance`, `/accounting/income-statement`, `/accounting/balance-sheet`, `/budgeting`, `/budgeting/budget-vs-actual`. All should load. Active sidebar item should highlight correctly.

- [ ] **Step 4: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat: sidebar — expand Finance section with COA, Vendors, Statements, Budgets"
```

---

## Task 3: Sidebar — Expand Quality and Sales & CRM sections

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

Quality shows only Inspections and NCRs — the Dashboard, Inspection Specs, NCR Templates, and Traceability pages are unreachable. CRM shows only Sales Orders and Customers — Products, Price Agreements, and Complaints are unreachable.

- [ ] **Step 1: Add icon imports**

Add to the lucide-react import in `Sidebar.tsx`:

```typescript
  Tag,
  MessageSquare,
  ClipboardList,
  GitFork,
```

- [ ] **Step 2: Replace the Quality section**

Find the existing Quality section and replace it:

```typescript
  {
    label: 'Quality',
    items: [
      { to: '/quality/dashboard',        label: 'Quality Dashboard', icon: LayoutDashboard, feature: 'quality', permission: 'quality.view' },
      { to: '/quality/inspection-specs', label: 'Inspection Specs',  icon: ClipboardList,   feature: 'quality', permission: 'quality.specs.view' },
      { to: '/quality/inspections',      label: 'Inspections',       icon: ShieldCheck,     feature: 'quality', permission: 'quality.view' },
      { to: '/quality/ncrs',             label: 'NCRs',              icon: AlertTriangle,   feature: 'quality', permission: 'quality.view', badgeKey: 'ncrs' },
      { to: '/quality/ncr-templates',    label: 'NCR Templates',     icon: FileText,        feature: 'quality', permission: 'quality.ncr.manage' },
      { to: '/quality/traceability',     label: 'Traceability',      icon: GitFork,         feature: 'quality', permission: 'quality.inspections.view' },
    ],
  },
```

- [ ] **Step 3: Replace the Sales & CRM section**

Find the existing `Sales & CRM` section and replace it:

```typescript
  {
    label: 'Sales & CRM',
    items: [
      { to: '/crm/sales-orders',     label: 'Sales Orders',    icon: Briefcase,      feature: 'crm', permission: 'crm.sales_orders.view', badgeKey: 'pending_so' },
      { to: '/crm/customers',        label: 'CRM Customers',   icon: Users2,         feature: 'crm', permission: 'crm.sales_orders.view' },
      { to: '/crm/products',         label: 'Products',        icon: Tag,            feature: 'crm', permission: 'crm.products.view' },
      { to: '/crm/price-agreements', label: 'Price Agreements', icon: FileText,       feature: 'crm', permission: 'crm.price_agreements.view' },
      { to: '/crm/complaints',       label: 'Complaints',      icon: MessageSquare,  feature: 'crm', permission: 'crm.complaints.manage' },
      { to: '/accounting/customers', label: 'AR Customers',    icon: Users,          feature: 'accounting', permission: 'accounting.customers.view' },
    ],
  },
```

- [ ] **Step 4: Verify**

Navigate to `/quality/dashboard`, `/quality/inspection-specs`, `/quality/ncr-templates`, `/quality/traceability`, `/crm/products`, `/crm/price-agreements`, `/crm/complaints`. All should load. Active sidebar highlight should track each route.

- [ ] **Step 5: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat: sidebar — expand Quality (dashboard, specs, templates, traceability) and CRM (products, price agreements, complaints)"
```

---

## Task 4: Sidebar — Expand Production section with MRP sub-items

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

Production shows Work Orders, MRP Plans, and Schedule. BOMs, Machines, Molds, Production Dashboard, and OEE are unreachable from the sidebar.

- [ ] **Step 1: Add icon imports**

Add to the lucide-react import in `Sidebar.tsx`:

```typescript
  ListTree,
  Cpu,
  Activity,
```

- [ ] **Step 2: Replace the Production section**

Find the existing `Production` section and replace it:

```typescript
  {
    label: 'Production',
    items: [
      { to: '/production/dashboard',   label: 'Dashboard',        icon: LayoutDashboard, feature: 'production', permission: 'production.dashboard.view' },
      { to: '/production/work-orders', label: 'Work Orders',      icon: FileText,        feature: 'production', permission: 'production.work_orders.view', badgeKey: 'work_orders' },
      { to: '/production/schedule',    label: 'Schedule (Gantt)', icon: CalendarClock,   feature: 'production', permission: 'production.schedule.view' },
      { to: '/production/oee',         label: 'OEE Report',       icon: Activity,        feature: 'production', permission: 'production.dashboard.view' },
      { to: '/mrp/plans',              label: 'MRP Plans',        icon: Layers,          feature: 'mrp', permission: 'mrp.plans.view' },
      { to: '/mrp/boms',               label: 'Bill of Materials', icon: ListTree,        feature: 'mrp', permission: 'mrp.boms.view' },
      { to: '/mrp/machines',           label: 'Machines',         icon: Cpu,             feature: 'mrp', permission: 'mrp.machines.view' },
      { to: '/mrp/molds',              label: 'Molds',            icon: Package,         feature: 'mrp', permission: 'mrp.molds.view' },
    ],
  },
```

- [ ] **Step 3: Verify**

Navigate to `/production/dashboard`, `/production/oee`, `/mrp/boms`, `/mrp/machines`, `/mrp/molds`. All should load. Sidebar active highlight follows the current route correctly (longest-prefix match).

- [ ] **Step 4: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat: sidebar — expand Production with Dashboard, OEE, BOMs, Machines, Molds"
```

---

## Task 5: Sidebar — Expand HR, Payroll, and Procurement sections

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

HR shows only Employees, Attendance, Leave, Payroll. Departments, Positions, Directory, and Separations are unreachable. Procurement shows only POs and PRs — Approved Suppliers is unreachable. Payroll has no link to Adjustments.

- [ ] **Step 1: Add icon imports**

Add to the lucide-react import in `Sidebar.tsx`:

```typescript
  Network,
  UserMinus,
  BadgeCheck,
  SlidersHorizontal,
```

- [ ] **Step 2: Replace the Human Resources section**

Find the existing `Human Resources` section and replace it:

```typescript
  {
    label: 'Human Resources',
    items: [
      { to: '/hr/employees',               label: 'Employees',        icon: Users,          feature: 'hr',         permission: 'hr.employees.view', badgeKey: 'profile_requests' },
      { to: '/hr/departments',             label: 'Departments',      icon: Building2,       feature: 'hr',         permission: 'hr.departments.view' },
      { to: '/hr/positions',               label: 'Positions',        icon: Briefcase,       feature: 'hr',         permission: 'hr.positions.view' },
      { to: '/hr/directory',               label: 'Directory',        icon: Network,         feature: 'hr',         permission: 'hr.directory.view' },
      { to: '/hr/separations',             label: 'Separations',      icon: UserMinus,       feature: 'hr',         permission: 'hr.separation.view' },
      { to: '/hr/attendance',              label: 'Attendance',       icon: Clock4,          feature: 'attendance', permission: 'attendance.view', badgeKey: 'leaves' },
      { to: '/hr/leaves',                  label: 'Leave',            icon: CalendarDays,    feature: 'leave',      permission: 'leave.view' },
      { to: '/payroll/periods',            label: 'Payroll',          icon: Wallet,          feature: 'payroll',    permission: 'payroll.view', badgeKey: 'payroll' },
      { to: '/payroll/adjustments',        label: 'Adjustments',      icon: SlidersHorizontal, feature: 'payroll', permission: 'payroll.view' },
    ],
  },
```

- [ ] **Step 3: Replace the Procurement section**

Find the existing `Procurement` section and replace it:

```typescript
  {
    label: 'Procurement',
    items: [
      { to: '/purchasing/purchase-orders',    label: 'Purchase Orders',    icon: ShoppingCart, feature: 'purchasing', permission: 'purchasing.view', badgeKey: 'purchase_requests' },
      { to: '/purchasing/purchase-requests',  label: 'Purchase Requests',  icon: FileText,     feature: 'purchasing', permission: 'purchasing.view' },
      { to: '/purchasing/approved-suppliers', label: 'Approved Suppliers', icon: BadgeCheck,   feature: 'purchasing', permission: 'purchasing.view' },
    ],
  },
```

- [ ] **Step 4: Verify**

Navigate to `/hr/departments`, `/hr/positions`, `/hr/directory`, `/hr/separations`, `/payroll/adjustments`, `/purchasing/approved-suppliers`. All should load without 404.

- [ ] **Step 5: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat: sidebar — expand HR (departments, positions, directory, separations), Payroll (adjustments), Procurement (approved suppliers)"
```

---

## Task 6: Sidebar — Expand Warehouse, Supply Chain, Maintenance, Admin sections

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

Supply Chain is missing Fleet. Maintenance is missing Machine Health and Downtime Analytics. Admin is missing Depreciation. Inventory (Warehouse) is missing Stock Levels and Movements.

- [ ] **Step 1: Replace the Warehouse section**

Find the existing `Warehouse` section and replace it:

```typescript
  {
    label: 'Warehouse',
    items: [
      { to: '/inventory/items',            label: 'Items',           icon: Boxes,    feature: 'inventory', permission: 'inventory.view', badgeKey: 'low_stock' },
      { to: '/inventory/grn',             label: 'Receiving (GRN)', icon: Package,  feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/material-issues', label: 'Issuance',        icon: FileEdit, feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/stock-levels',    label: 'Stock Levels',    icon: BarChart2, feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/movements',       label: 'Movements',       icon: ArrowLeftRight, feature: 'inventory', permission: 'inventory.view' },
    ],
  },
```

- [ ] **Step 2: Replace the Supply Chain section**

Find the existing `Supply Chain` section and replace it:

```typescript
  {
    label: 'Supply Chain',
    items: [
      { to: '/supply-chain/deliveries', label: 'Deliveries', icon: Truck,   feature: 'supply_chain', permission: 'supply_chain.view', badgeKey: 'deliveries' },
      { to: '/supply-chain/shipments',  label: 'Shipments',  icon: Package, feature: 'supply_chain', permission: 'supply_chain.view' },
      { to: '/supply-chain/fleet',      label: 'Fleet',      icon: Truck,   feature: 'supply_chain', permission: 'supply_chain.view' },
    ],
  },
```

Note: `Truck` is already imported — reusing it for Fleet is fine since it's a different nav context.

- [ ] **Step 3: Replace the Maintenance section**

Find the existing `Maintenance` section and replace it:

```typescript
  {
    label: 'Maintenance',
    items: [
      { to: '/maintenance/work-orders',    label: 'Work Orders',    icon: Wrench,         feature: 'maintenance', permission: 'maintenance.view', badgeKey: 'maintenance_wo' },
      { to: '/maintenance/schedules',      label: 'Schedules',      icon: Calendar,       feature: 'maintenance', permission: 'maintenance.view' },
      { to: '/maintenance/machine-health', label: 'Machine Health', icon: Activity,       feature: 'maintenance', permission: 'maintenance.view' },
      { to: '/maintenance/downtime',       label: 'Downtime',       icon: BarChart2,      feature: 'maintenance', permission: 'maintenance.view' },
    ],
  },
```

- [ ] **Step 4: Replace the Administration section**

Find the existing `Administration` section and replace it:

```typescript
  {
    label: 'Administration',
    items: [
      { to: '/admin/users',        label: 'Users',        icon: Users2,       permission: 'admin.users.manage' },
      { to: '/admin/roles',        label: 'Roles',        icon: ShieldCheck,  permission: 'admin.roles.manage' },
      { to: '/admin/audit-logs',   label: 'Audit Logs',   icon: FileText,     permission: 'admin.audit_logs.view' },
      { to: '/admin/settings',     label: 'Settings',     icon: SettingsIcon, permission: 'admin.settings.manage' },
      { to: '/admin/depreciation', label: 'Depreciation', icon: BarChart2,    permission: 'assets.depreciation.view' },
    ],
  },
```

- [ ] **Step 5: Verify all new nav items**

Test: `/inventory/stock-levels`, `/inventory/movements`, `/supply-chain/fleet`, `/maintenance/machine-health`, `/maintenance/downtime`, `/admin/depreciation`.

- [ ] **Step 6: Commit**

```bash
git add spa/src/components/layout/Sidebar.tsx
git commit -m "feat: sidebar — expand Warehouse, Supply Chain, Maintenance, Admin sections"
```

---

## Task 7: COA Create/Edit pages + route registration + fix broken button

**Files:**
- Modify: `spa/src/routes/accountingRoutes.tsx`
- Create: `spa/src/pages/accounting/coa/create.tsx`
- Create: `spa/src/pages/accounting/coa/edit.tsx`
- Modify: `spa/src/pages/accounting/coa/index.tsx`

The COA list has an "Add account" button that uses `window.location.href = '/accounting/coa/create'` but no create page or route exists. `accountsApi.create()` and `accountsApi.update()` both exist.

- [ ] **Step 1: Create `spa/src/pages/accounting/coa/create.tsx`**

```typescript
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';
import type { AccountType } from '@/types/accounting';

const schema = z.object({
  code:           z.string().min(1, 'Code required').max(20),
  name:           z.string().min(1, 'Name required').max(100),
  type:           z.enum(['asset', 'liability', 'equity', 'revenue', 'expense']),
  normal_balance: z.enum(['debit', 'credit']).optional(),
  parent_id:      z.string().optional().or(z.literal('')),
  description:    z.string().max(500).optional().or(z.literal('')),
});
type FormValues = z.infer<typeof schema>;

const TYPE_DEFAULTS: Record<AccountType, 'debit' | 'credit'> = {
  asset: 'debit', expense: 'debit',
  liability: 'credit', equity: 'credit', revenue: 'credit',
};

export default function CreateAccountPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: accounts } = useQuery({
    queryKey: ['accounting', 'accounts', 'list'],
    queryFn: () => accountsApi.list({ per_page: 200 }),
    staleTime: 60_000,
  });

  const { register, handleSubmit, watch, setValue, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { type: 'asset', normal_balance: 'debit' },
  });

  const watchType = watch('type') as AccountType | undefined;

  const mutation = useMutation({
    mutationFn: (data: FormValues) => accountsApi.create({
      code: data.code,
      name: data.name,
      type: data.type,
      normal_balance: data.normal_balance || TYPE_DEFAULTS[data.type as AccountType],
      parent_id: data.parent_id || null,
      description: data.description || undefined,
    }),
    onSuccess: (account) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'accounts'] });
      toast.success(`Account ${account.code} created.`);
      navigate('/accounting/coa');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to create account.');
      }
    },
  });

  return (
    <div>
      <PageHeader title="New account" backTo="/accounting/coa" backLabel="Chart of Accounts"
        breadcrumbs={[{ label: 'Finance', href: '/accounting/coa' }, { label: 'COA', href: '/accounting/coa' }, { label: 'New' }]} />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-2xl mx-auto px-5 py-6 space-y-4">

        <div className="grid grid-cols-2 gap-3">
          <Input label="Account code" {...register('code')} error={errors.code?.message} required
            placeholder="e.g. 1010" className="font-mono" />
          <Select label="Type" {...register('type', {
            onChange: (e) => setValue('normal_balance', TYPE_DEFAULTS[e.target.value as AccountType]),
          })} error={errors.type?.message} required>
            <option value="asset">Asset</option>
            <option value="liability">Liability</option>
            <option value="equity">Equity</option>
            <option value="revenue">Revenue</option>
            <option value="expense">Expense</option>
          </Select>
        </div>

        <Input label="Account name" {...register('name')} error={errors.name?.message} required
          placeholder="e.g. Cash on Hand" />

        <div className="grid grid-cols-2 gap-3">
          <Select label="Normal balance" {...register('normal_balance')} error={errors.normal_balance?.message}>
            <option value="debit">Debit</option>
            <option value="credit">Credit</option>
          </Select>
          <Select label="Parent account (optional)" {...register('parent_id')} error={errors.parent_id?.message}>
            <option value="">— None (top-level) —</option>
            {accounts?.data
              .filter((a) => !a.is_leaf)
              .map((a) => (
                <option key={a.id} value={a.id}>{a.code} — {a.name}</option>
              ))}
          </Select>
        </div>

        <Textarea label="Description (optional)" {...register('description')} rows={2}
          error={errors.description?.message} />

        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/coa')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Create account</Button>
        </div>
      </form>
    </div>
  );
}
```

- [ ] **Step 2: Create `spa/src/pages/accounting/coa/edit.tsx`**

```typescript
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { accountsApi } from '@/api/accounting/accounts';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  name:           z.string().min(1, 'Name required').max(100),
  description:    z.string().max(500).optional().or(z.literal('')),
  is_active:      z.coerce.boolean(),
});
type FormValues = z.infer<typeof schema>;

export default function EditAccountPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: account, isLoading } = useQuery({
    queryKey: ['accounting', 'accounts', id],
    queryFn: () => accountsApi.show(id),
    enabled: !!id,
  });

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: account ? {
      name: account.name,
      description: account.description ?? '',
      is_active: account.is_active,
    } : undefined,
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => accountsApi.update(id, {
      name: data.name,
      description: data.description || undefined,
      is_active: data.is_active,
    }),
    onSuccess: (account) => {
      qc.invalidateQueries({ queryKey: ['accounting', 'accounts'] });
      toast.success(`Account ${account.code} updated.`);
      navigate('/accounting/coa');
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to update account.');
      }
    },
  });

  if (isLoading || !account) return <SkeletonDetail />;

  return (
    <div>
      <PageHeader title={`Edit ${account.code}`} backTo="/accounting/coa" backLabel="Chart of Accounts"
        breadcrumbs={[{ label: 'COA', href: '/accounting/coa' }, { label: account.code }]} />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-2xl mx-auto px-5 py-6 space-y-4">

        <div className="grid grid-cols-2 gap-3">
          <div>
            <p className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Code</p>
            <p className="font-mono text-sm">{account.code}</p>
          </div>
          <div>
            <p className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Type</p>
            <p className="text-sm capitalize">{account.type}</p>
          </div>
        </div>

        <Input label="Account name" {...register('name')} error={errors.name?.message} required />

        <Textarea label="Description (optional)" {...register('description')} rows={2}
          error={errors.description?.message} />

        <Select label="Status" {...register('is_active')} error={errors.is_active?.message}>
          <option value="true">Active</option>
          <option value="false">Inactive</option>
        </Select>

        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/accounting/coa')}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Save changes</Button>
        </div>
      </form>
    </div>
  );
}
```

- [ ] **Step 3: Register routes in `spa/src/routes/accountingRoutes.tsx`**

Add two lazy imports after the existing `ChartOfAccountsPage` import line:

```typescript
const CreateAccountPage = lazy(() => import('@/pages/accounting/coa/create'));
const EditAccountPage   = lazy(() => import('@/pages/accounting/coa/edit'));
```

Add two routes after the existing `/accounting/coa` route:

```typescript
      <Route path="/accounting/coa/create"
        element={<PermissionGuard permission="accounting.coa.manage"><CreateAccountPage /></PermissionGuard>} />
      <Route path="/accounting/coa/:id/edit"
        element={<PermissionGuard permission="accounting.coa.manage"><EditAccountPage /></PermissionGuard>} />
```

- [ ] **Step 4: Fix "Add account" button in `spa/src/pages/accounting/coa/index.tsx`**

Replace the broken `window.location.href` button with a proper Link. First add the import at the top (it already imports `Link` from react-router-dom — verify, then use it). Find:

```typescript
              <Button variant="primary" size="sm" onClick={() => window.location.href = '/accounting/coa/create'}>
                Add account
              </Button>
```

Replace with:

```typescript
              <Link to="/accounting/coa/create">
                <Button variant="primary" size="sm">Add account</Button>
              </Link>
```

Also add an "Edit" link to each row in the `TreeRow` component. Locate where the account name link is rendered and add an edit button next to it, gated by `can('accounting.coa.manage')`. You'll need to thread `canManage: boolean` down from `ChartOfAccountsPage` (which already calls `usePermission`) into `TreeRow`. Add the prop and render:

```typescript
// In ChartOfAccountsPage, pass canManage to TreeRow:
{data.map((root) => (
  <TreeRow key={root.id} node={root} depth={0} expanded={expanded} onToggle={toggle} canManage={can('accounting.coa.manage')} />
))}

// TreeRow function signature update:
function TreeRow({
  node, depth, expanded, onToggle, canManage,
}: { node: Account; depth: number; expanded: Set<string>; onToggle: (id: string) => void; canManage: boolean }) {

// Inside TreeRow, after the account name Link, add:
  {canManage && (
    <Link
      to={`/accounting/coa/${node.id}/edit`}
      onClick={(e) => e.stopPropagation()}
      className="ml-1 text-xs text-muted hover:text-accent opacity-0 group-hover:opacity-100 transition-opacity"
    >
      Edit
    </Link>
  )}
```

Add `group` class to the row div to enable `group-hover`:

```typescript
<div className={cn('group grid grid-cols-12 h-8 px-2.5 items-center border-b border-subtle hover:bg-subtle text-sm', !node.is_active && 'opacity-60')}>
```

- [ ] **Step 5: Verify**

1. Visit `/accounting/coa` → click "Add account" → should navigate to `/accounting/coa/create`
2. Fill out the create form → submit → should redirect back to COA list with the new account
3. Hover a row → click "Edit" → should load the edit form with correct pre-filled values
4. Save → should redirect back to COA list

- [ ] **Step 6: Commit**

```bash
git add spa/src/routes/accountingRoutes.tsx spa/src/pages/accounting/coa/
git commit -m "feat: COA create/edit pages — account authoring with route registration and fix broken button"
```

---

## Task 8: Material Issue detail page + route registration

**Files:**
- Modify: `spa/src/routes/inventoryRoutes.tsx`
- Create: `spa/src/pages/inventory/material-issues/detail.tsx`

The material issues list renders `<Link to={`/inventory/material-issues/${r.id}`}>` on each slip number, but no route is registered for that path. `materialIssuesApi.show(id)` already exists.

- [ ] **Step 1: Create `spa/src/pages/inventory/material-issues/detail.tsx`**

```typescript
import { useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { StatCard } from '@/components/ui/StatCard';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';

export default function MaterialIssueDetailPage() {
  const { id = '' } = useParams<{ id: string }>();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'material-issues', id],
    queryFn: () => materialIssuesApi.show(id),
    enabled: !!id,
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load material issue slip"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
  );

  const statusVariant = (s: string) => {
    if (s === 'issued')    return 'info' as const;
    if (s === 'cancelled') return 'neutral' as const;
    return 'warning' as const;
  };

  return (
    <div>
      <PageHeader
        title={<span className="font-mono">{data.slip_number}</span>}
        backTo="/inventory/material-issues"
        backLabel="Material Issues"
        breadcrumbs={[
          { label: 'Warehouse', href: '/inventory/items' },
          { label: 'Material Issues', href: '/inventory/material-issues' },
          { label: data.slip_number },
        ]}
        actions={<Chip variant={statusVariant(data.status)}>{data.status}</Chip>}
      />

      <div className="px-5 pt-3 pb-4 grid grid-cols-4 gap-2">
        <StatCard label="Total value"   value={formatPeso(data.total_value)} />
        <StatCard label="Issued date"   value={formatDate(data.issued_date)} />
        <StatCard label="Issued by"     value={data.issuer?.name ?? '—'} />
        <StatCard label="Work order"    value={data.work_order_id ? `WO #${data.work_order_id}` : (data.reference_text ?? '—')} />
      </div>

      <div className="px-5 pb-4 space-y-4">
        <Panel title="Line items" meta={`${data.items?.length ?? 0} lines`} noPadding>
          <table className="w-full text-xs">
            <thead className="bg-subtle">
              <tr>
                <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Item</th>
                <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Location</th>
                <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Qty issued</th>
                <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Unit cost</th>
                <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Total</th>
              </tr>
            </thead>
            <tbody>
              {(data.items ?? []).map((line) => (
                <tr key={line.id} className="border-t border-subtle hover:bg-subtle">
                  <td className="px-2.5 py-2">
                    <div className="font-mono">{line.item?.code ?? '—'}</div>
                    <div className="text-muted">{line.item?.name}</div>
                  </td>
                  <td className="px-2.5 py-2 font-mono">{line.location?.code ?? '—'}</td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums">
                    {Number(line.quantity_issued).toFixed(4)} {line.item?.unit_of_measure}
                  </td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums">{formatPeso(line.unit_cost)}</td>
                  <td className="px-2.5 py-2 text-right font-mono tabular-nums font-medium">{formatPeso(line.total_cost)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Panel>

        {data.remarks && (
          <Panel title="Remarks">
            <p className="text-sm">{data.remarks}</p>
          </Panel>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Register route in `spa/src/routes/inventoryRoutes.tsx`**

Add a lazy import after `CreateMaterialIssuePage`:

```typescript
const MaterialIssueDetailPage   = lazy(() => import('@/pages/inventory/material-issues/detail'));
```

Add a route after the existing `/inventory/material-issues/create` route:

```typescript
      <Route path="/inventory/material-issues/:id"
        element={<PermissionGuard permission="inventory.view"><MaterialIssueDetailPage /></PermissionGuard>} />
```

- [ ] **Step 3: Verify**

1. Navigate to `/inventory/material-issues`
2. Click a slip number link → should open the detail page with header, stat cards, and line items table
3. Back button should return to the list

- [ ] **Step 4: Commit**

```bash
git add spa/src/routes/inventoryRoutes.tsx spa/src/pages/inventory/material-issues/detail.tsx
git commit -m "feat: material issue detail page — show slip header, stat cards, line items table"
```

---

## Task 9: BOM Edit page + route + edit/delete buttons on detail

**Files:**
- Modify: `spa/src/routes/mrpRoutes.tsx`
- Create: `spa/src/pages/mrp/boms/edit.tsx`
- Modify: `spa/src/pages/mrp/boms/detail.tsx`

`bomsApi.update()` and `bomsApi.delete()` exist but there's no edit page, no edit route, and no buttons on the BOM detail page to trigger them.

- [ ] **Step 1: Create `spa/src/pages/mrp/boms/edit.tsx`**

The edit form is the same as create but:
- loads existing BOM items as default values
- `product_id` is read-only (displayed as text, not a select — changing product = creating a new BOM)
- calls `bomsApi.update(id, payload)` instead of `create`

```typescript
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { AxiosError } from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { onFormInvalid } from '@/lib/formErrors';
import { bomsApi } from '@/api/mrp/boms';
import { itemsApi } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';

const itemSchema = z.object({
  item_id:           z.string().min(1, 'Item required'),
  quantity_per_unit: z.string().regex(/^\d+(\.\d{1,4})?$/).refine((v) => Number(v) > 0, 'Must be > 0'),
  unit:              z.string().min(1).max(20),
  waste_factor:      z.string().regex(/^\d+(\.\d{1,2})?$/).optional().or(z.literal('')),
});
const schema = z.object({
  items: z.array(itemSchema).min(1, 'Add at least one line'),
});
type FormValues = z.infer<typeof schema>;

export default function EditBomPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: bom, isLoading: bomLoading } = useQuery({
    queryKey: ['mrp', 'boms', 'detail', id],
    queryFn: () => bomsApi.show(id),
    enabled: !!id,
  });

  const items = useQuery({
    queryKey: ['inventory', 'items', 'lookup'],
    queryFn: () => itemsApi.list({ per_page: 200 }),
  });

  const { register, control, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: bom ? {
      items: (bom.items ?? []).map((m) => ({
        item_id:           m.item?.id ?? '',
        quantity_per_unit: Number(m.quantity_per_unit).toFixed(4),
        unit:              m.unit,
        waste_factor:      Number(m.waste_factor).toFixed(2),
      })),
    } : undefined,
  });

  const { fields, append, remove } = useFieldArray({ control, name: 'items' });

  const update = useMutation({
    mutationFn: (values: FormValues) => bomsApi.update(id, {
      product_id: bom!.product!.id,
      items: values.items.map((row, i) => ({
        item_id: row.item_id, quantity_per_unit: row.quantity_per_unit,
        unit: row.unit, waste_factor: row.waste_factor || '0', sort_order: i,
      })),
    }),
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success(`BOM v${updated.version} saved.`);
      navigate(`/mrp/boms/${id}`);
    },
    onError: (e: AxiosError<{ message?: string; errors?: Record<string, string[]> }>) => {
      if (e.response?.status === 422 && e.response.data.errors) {
        Object.entries(e.response.data.errors).forEach(([field, msgs]) =>
          setError(field as never, { type: 'server', message: msgs[0] }));
        toast.error(e.response?.data?.message || 'Validation failed.');
      } else {
        toast.error(e.response?.data?.message ?? 'Failed to save BOM.');
      }
    },
  });

  if (bomLoading || !bom) return <SkeletonDetail />;

  return (
    <div>
      <PageHeader
        title={`Edit BOM — ${bom.product?.part_number ?? '?'}`}
        backTo={`/mrp/boms/${id}`}
        backLabel="BOM detail"
        breadcrumbs={[
          { label: 'MRP', href: '/mrp' },
          { label: 'BOMs', href: '/mrp/boms' },
          { label: bom.product?.part_number ?? 'BOM' },
          { label: 'Edit' },
        ]}
      />
      <form
        onSubmit={handleSubmit((v) => update.mutate(v), onFormInvalid<FormValues>())}
        className="max-w-4xl mx-auto px-5 py-6"
      >
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Product (read-only)</legend>
          <p className="text-sm">
            <span className="font-mono">{bom.product?.part_number}</span>
            <span className="ml-2 text-muted">{bom.product?.name}</span>
          </p>
          <p className="text-xs text-muted mt-1">To change the product, create a new BOM instead.</p>
        </fieldset>

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Material lines</legend>
          <div className="border border-default rounded-md overflow-hidden">
            <table className="w-full text-xs">
              <thead className="bg-subtle">
                <tr>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2 w-2/5">Item</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Qty / unit</th>
                  <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">UOM</th>
                  <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-2.5 py-2">Waste %</th>
                  <th className="px-2 py-2" />
                </tr>
              </thead>
              <tbody>
                {fields.map((field, i) => (
                  <tr key={field.id} className="border-t border-subtle">
                    <td className="px-2.5 py-1.5">
                      <Select {...register(`items.${i}.item_id` as const)} error={errors.items?.[i]?.item_id?.message}>
                        <option value="">Select item…</option>
                        {items.data?.data.map((it: { id: string; code: string; name: string }) => (
                          <option key={it.id} value={it.id}>{it.code} — {it.name}</option>
                        ))}
                      </Select>
                    </td>
                    <td className="px-2.5 py-1.5 text-right">
                      <Input {...register(`items.${i}.quantity_per_unit` as const)}
                        error={errors.items?.[i]?.quantity_per_unit?.message}
                        placeholder="0.0000" className="font-mono text-right" />
                    </td>
                    <td className="px-2.5 py-1.5">
                      <Input {...register(`items.${i}.unit` as const)}
                        error={errors.items?.[i]?.unit?.message} placeholder="kg" className="font-mono" />
                    </td>
                    <td className="px-2.5 py-1.5 text-right">
                      <Input {...register(`items.${i}.waste_factor` as const)}
                        error={errors.items?.[i]?.waste_factor?.message}
                        placeholder="0.00" className="font-mono text-right" />
                    </td>
                    <td className="px-2 py-1.5 text-right">
                      <button type="button" onClick={() => remove(i)} disabled={fields.length === 1}
                        className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm disabled:opacity-40 disabled:cursor-not-allowed">
                        <Trash2 size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="mt-3">
            <Button type="button" variant="secondary" size="sm" icon={<Plus size={14} />}
              onClick={() => append({ item_id: '', quantity_per_unit: '', unit: '', waste_factor: '0' })}>
              Add line
            </Button>
          </div>
          {errors.items?.message && <p className="mt-2 text-xs text-danger">{errors.items.message as string}</p>}
        </fieldset>

        <div className="flex items-center justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate(`/mrp/boms/${id}`)}>Cancel</Button>
          <Button type="submit" variant="primary" loading={update.isPending}>
            {update.isPending ? 'Saving…' : 'Save BOM'}
          </Button>
        </div>
      </form>
    </div>
  );
}
```

- [ ] **Step 2: Register route in `spa/src/routes/mrpRoutes.tsx`**

Add a lazy import after `BomDetailPage`:

```typescript
const EditBomPage = lazy(() => import('@/pages/mrp/boms/edit'));
```

Add a route after the existing `/mrp/boms/:id` route:

```typescript
      <Route path="/mrp/boms/:id/edit"
        element={<PermissionGuard permission="mrp.boms.manage"><EditBomPage /></PermissionGuard>} />
```

- [ ] **Step 3: Add Edit and Delete buttons to `spa/src/pages/mrp/boms/detail.tsx`**

Add imports at the top of the file:

```typescript
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Pencil, Trash2 } from 'lucide-react';
import { usePermission } from '@/hooks/usePermission';
```

Inside the `BomDetailPage` component, add hook calls after `useQuery`:

```typescript
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();

  const deleteMut = useMutation({
    mutationFn: () => bomsApi.delete(id!),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['mrp', 'boms'] });
      toast.success('BOM deleted.');
      navigate('/mrp/boms');
    },
    onError: () => toast.error('Failed to delete BOM.'),
  });

  const handleDelete = () => {
    if (!window.confirm(`Delete BOM for ${data?.product?.part_number ?? 'this product'}? This cannot be undone.`)) return;
    deleteMut.mutate();
  };
```

Update the `PageHeader` to include action buttons:

```typescript
        actions={
          can('mrp.boms.manage') ? (
            <div className="flex gap-1.5">
              <Button variant="secondary" size="sm" icon={<Pencil size={14} />}
                onClick={() => navigate(`/mrp/boms/${id}/edit`)}>
                Edit
              </Button>
              <Button variant="danger" size="sm" icon={<Trash2 size={14} />}
                loading={deleteMut.isPending}
                onClick={handleDelete}>
                Delete
              </Button>
            </div>
          ) : undefined
        }
```

- [ ] **Step 4: Verify**

1. Navigate to any BOM detail page → Edit and Delete buttons appear for users with `mrp.boms.manage`
2. Click Edit → edit form loads with all current material lines pre-filled, product shown as read-only text
3. Save → redirects back to BOM detail, version remains same (update, not new version)
4. Click Delete → confirmation dialog appears → confirm → redirects to BOM list

- [ ] **Step 5: Commit**

```bash
git add spa/src/routes/mrpRoutes.tsx spa/src/pages/mrp/boms/
git commit -m "feat: BOM edit page + delete — edit route, edit/delete buttons on detail"
```

---

## Task 10: Asset Edit page + route + edit button on detail

**Files:**
- Modify: `spa/src/routes/assetsRoutes.tsx`
- Create: `spa/src/pages/assets/edit.tsx`
- Modify: `spa/src/pages/assets/detail.tsx`

`assetsApi.update()` exists but there's no edit page, no route, and the asset detail page has no "Edit" button. Edit is only allowed when status is `active` or `under_maintenance` (not `disposed`).

- [ ] **Step 1: Create `spa/src/pages/assets/edit.tsx`**

```typescript
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { assetsApi } from '@/api/assets';
import { departmentsApi } from '@/api/hr/departments';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  name:         z.string().min(1, 'Name required').max(200),
  description:  z.string().max(5000).optional().or(z.literal('')),
  location:     z.string().max(100).optional().or(z.literal('')),
  department_id: z.coerce.number().int().optional().nullable(),
});
type FormValues = z.infer<typeof schema>;

export default function EditAssetPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: asset, isLoading: assetLoading } = useQuery({
    queryKey: ['asset', id],
    queryFn: () => assetsApi.show(id),
    enabled: !!id,
  });

  const { data: deptData } = useQuery({
    queryKey: ['hr', 'departments', 'list'],
    queryFn: () => departmentsApi.list({ per_page: 200 }),
    staleTime: 300_000,
  });

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: asset ? {
      name: asset.name,
      description: asset.description ?? '',
      location: asset.location ?? '',
      department_id: asset.department_id ?? null,
    } : undefined,
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => assetsApi.update(id, {
      name: data.name,
      description: data.description || undefined,
      location: data.location || undefined,
      department_id: data.department_id ?? null,
    }),
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['asset', id] });
      qc.invalidateQueries({ queryKey: ['assets'] });
      toast.success(`Asset ${updated.asset_code} updated.`);
      navigate(`/assets/${id}`);
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to update asset.');
      }
    },
  });

  if (assetLoading || !asset) return <SkeletonDetail />;

  return (
    <div>
      <PageHeader
        title={`Edit ${asset.asset_code}`}
        subtitle={asset.name}
        backTo={`/assets/${id}`}
        backLabel="Asset detail"
        breadcrumbs={[{ label: 'Assets', href: '/assets' }, { label: asset.asset_code }, { label: 'Edit' }]}
      />
      <form
        onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-2xl mx-auto px-5 py-6 space-y-4"
      >
        <div className="grid grid-cols-2 gap-3 text-sm p-3 bg-subtle rounded-md border border-default">
          <div><span className="text-muted text-xs uppercase tracking-wider">Category</span><p className="mt-0.5 capitalize">{asset.category}</p></div>
          <div><span className="text-muted text-xs uppercase tracking-wider">Acquisition cost</span><p className="mt-0.5 font-mono">₱ {asset.acquisition_cost}</p></div>
        </div>

        <Input label="Name" {...register('name')} error={errors.name?.message} required />

        <div className="grid grid-cols-2 gap-3">
          <Input label="Location" {...register('location')} error={errors.location?.message}
            placeholder="e.g. Production floor Bay 3" />
          <Select label="Department" {...register('department_id')} error={errors.department_id?.message}>
            <option value="">— None —</option>
            {deptData?.data?.map((d) => (
              <option key={d.id} value={d.id}>{d.name}</option>
            ))}
          </Select>
        </div>

        <Textarea label="Description (optional)" {...register('description')} rows={3}
          error={errors.description?.message} />

        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate(`/assets/${id}`)}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Save changes</Button>
        </div>
      </form>
    </div>
  );
}
```

- [ ] **Step 2: Register route in `spa/src/routes/assetsRoutes.tsx`**

Add a lazy import after `AssetDetailPage`:

```typescript
const EditAssetPage = lazy(() => import('@/pages/assets/edit'));
```

Add a route after the existing `/assets/:id` route:

```typescript
      <Route path="/assets/:id/edit"
        element={<PermissionGuard permission="assets.create"><EditAssetPage /></PermissionGuard>} />
```

- [ ] **Step 3: Add Edit button to `spa/src/pages/assets/detail.tsx`**

Find the `actions` prop on `PageHeader` in the asset detail page. The current actions block looks like:

```typescript
        actions={
          <div className="flex gap-1.5 items-center">
            <Chip variant={...}>{data.status.replace('_', ' ')}</Chip>
            {data.status === 'active' && can('assets.dispose') && (
              <Button variant="danger" size="sm" onClick={() => setDisposeOpen(true)}>Dispose</Button>
            )}
          </div>
        }
```

Add `import { useNavigate } from 'react-router-dom'` and `import { Pencil } from 'lucide-react'` to the import section. Add `const navigate = useNavigate()` inside the component. Then update the actions:

```typescript
        actions={
          <div className="flex gap-1.5 items-center">
            <Chip variant={data.status === 'active' ? 'success' : data.status === 'under_maintenance' ? 'warning' : 'neutral'}>
              {data.status.replace('_', ' ')}
            </Chip>
            {data.status !== 'disposed' && can('assets.create') && (
              <Button variant="secondary" size="sm" icon={<Pencil size={14} />}
                onClick={() => navigate(`/assets/${id}/edit`)}>
                Edit
              </Button>
            )}
            {data.status === 'active' && can('assets.dispose') && (
              <Button variant="danger" size="sm" onClick={() => setDisposeOpen(true)}>Dispose</Button>
            )}
          </div>
        }
```

- [ ] **Step 4: Verify**

1. Navigate to any active asset detail → "Edit" button is visible
2. Click Edit → pre-filled form with current name, location, department, description
3. Readonly block shows category and acquisition cost (not editable)
4. Save → redirects back to detail, changes reflected
5. Navigate to a disposed asset → "Edit" button is hidden

- [ ] **Step 5: Commit**

```bash
git add spa/src/routes/assetsRoutes.tsx spa/src/pages/assets/
git commit -m "feat: asset edit page — edit route and edit button on detail (name, location, department)"
```

---

## Task 11: Maintenance Schedule detail + edit pages + routes + list edit/delete buttons

**Files:**
- Modify: `spa/src/routes/maintenanceRoutes.tsx`
- Create: `spa/src/pages/maintenance/schedules/detail.tsx`
- Create: `spa/src/pages/maintenance/schedules/edit.tsx`
- Modify: `spa/src/pages/maintenance/schedules/index.tsx`

`schedulesApi.update()` and `schedulesApi.destroy()` exist but no detail page, no edit page, and no edit/delete buttons on the list.

- [ ] **Step 1: Create `spa/src/pages/maintenance/schedules/detail.tsx`**

```typescript
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Pencil, Trash2 } from 'lucide-react';
import { schedulesApi } from '@/api/maintenance/schedules';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';

export default function MaintenanceScheduleDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['maintenance', 'schedules', id],
    queryFn: () => schedulesApi.show(id),
    enabled: !!id,
  });

  const deleteMut = useMutation({
    mutationFn: () => schedulesApi.destroy(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
      toast.success('Schedule deleted.');
      navigate('/maintenance/schedules');
    },
    onError: () => toast.error('Failed to delete schedule.'),
  });

  const handleDelete = () => {
    if (!window.confirm(`Delete this maintenance schedule? This cannot be undone.`)) return;
    deleteMut.mutate();
  };

  if (isLoading) return <SkeletonDetail />;
  if (isError || !data) return (
    <EmptyState icon="alert-circle" title="Failed to load schedule"
      action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
  );

  return (
    <div>
      <PageHeader
        title={data.description}
        backTo="/maintenance/schedules"
        backLabel="Schedules"
        breadcrumbs={[
          { label: 'Maintenance', href: '/maintenance/schedules' },
          { label: 'Schedules', href: '/maintenance/schedules' },
          { label: data.description.length > 40 ? data.description.slice(0, 40) + '…' : data.description },
        ]}
        actions={
          can('maintenance.schedules.manage') ? (
            <div className="flex gap-1.5">
              <Chip variant={data.is_active ? 'success' : 'neutral'}>{data.is_active ? 'Active' : 'Disabled'}</Chip>
              <Button variant="secondary" size="sm" icon={<Pencil size={14} />}
                onClick={() => navigate(`/maintenance/schedules/${id}/edit`)}>
                Edit
              </Button>
              <Button variant="danger" size="sm" icon={<Trash2 size={14} />}
                loading={deleteMut.isPending} onClick={handleDelete}>
                Delete
              </Button>
            </div>
          ) : (
            <Chip variant={data.is_active ? 'success' : 'neutral'}>{data.is_active ? 'Active' : 'Disabled'}</Chip>
          )
        }
      />

      <div className="px-5 py-4">
        <Panel title="Schedule details">
          <dl className="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
              <dt className="text-xs uppercase tracking-wider text-muted font-medium">Target type</dt>
              <dd className="mt-0.5 capitalize">{data.maintainable_type}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-muted font-medium">Target</dt>
              <dd className="mt-0.5 font-mono">{data.maintainable?.code ?? '—'} {data.maintainable?.name}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-muted font-medium">Interval</dt>
              <dd className="mt-0.5 font-mono tabular-nums">{data.interval_value} {data.interval_type}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-muted font-medium">Work orders</dt>
              <dd className="mt-0.5 font-mono tabular-nums">{data.work_orders_count ?? 0}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-muted font-medium">Last performed</dt>
              <dd className="mt-0.5 font-mono">{data.last_performed_at ? formatDate(data.last_performed_at) : '—'}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wider text-muted font-medium">Next due</dt>
              <dd className="mt-0.5 font-mono">{data.next_due_at ? formatDate(data.next_due_at) : '—'}</dd>
            </div>
          </dl>
        </Panel>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Create `spa/src/pages/maintenance/schedules/edit.tsx`**

```typescript
import { useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import type { AxiosError } from 'axios';
import { schedulesApi } from '@/api/maintenance/schedules';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { onFormInvalid } from '@/lib/formErrors';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  description:    z.string().min(1).max(200),
  interval_type:  z.enum(['hours', 'days', 'shots']),
  interval_value: z.coerce.number().int().min(1),
  is_active:      z.coerce.boolean(),
}).refine((d) => !(d.interval_type === 'shots'), {
  // shots-interval validation is backend-enforced; just allow it here
  message: '', path: [],
});
type FormValues = z.infer<typeof schema>;

export default function EditMaintenanceSchedulePage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();

  const { data: schedule, isLoading } = useQuery({
    queryKey: ['maintenance', 'schedules', id],
    queryFn: () => schedulesApi.show(id),
    enabled: !!id,
  });

  const { register, handleSubmit, setError, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    values: schedule ? {
      description:    schedule.description,
      interval_type:  schedule.interval_type,
      interval_value: schedule.interval_value,
      is_active:      schedule.is_active,
    } : undefined,
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => schedulesApi.update(id, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
      toast.success('Schedule updated.');
      navigate(`/maintenance/schedules/${id}`);
    },
    onError: (err: AxiosError<ApiValidationError>) => {
      if (err.response?.status === 422 && err.response.data.errors) {
        Object.entries(err.response.data.errors).forEach(([k, v]) =>
          setError(k as keyof FormValues, { type: 'server', message: v[0] }));
        toast.error(err.response?.data?.message || 'Validation failed.');
      } else {
        toast.error('Failed to update schedule.');
      }
    },
  });

  if (isLoading || !schedule) return <SkeletonDetail />;

  return (
    <div>
      <PageHeader title="Edit schedule" backTo={`/maintenance/schedules/${id}`} backLabel="Schedule detail"
        breadcrumbs={[
          { label: 'Maintenance', href: '/maintenance/schedules' },
          { label: 'Schedules', href: '/maintenance/schedules' },
          { label: schedule.description.slice(0, 30) },
          { label: 'Edit' },
        ]}
      />
      <form onSubmit={handleSubmit((d) => mutation.mutate(d), onFormInvalid<FormValues>())}
        className="max-w-2xl mx-auto px-5 py-6 space-y-4">

        <div className="grid grid-cols-2 gap-3 text-sm p-3 bg-subtle rounded-md border border-default">
          <div><span className="text-muted text-xs uppercase tracking-wider">Target type</span>
            <p className="mt-0.5 capitalize">{schedule.maintainable_type}</p></div>
          <div><span className="text-muted text-xs uppercase tracking-wider">Target</span>
            <p className="mt-0.5 font-mono">{schedule.maintainable?.code ?? schedule.maintainable_id}</p></div>
        </div>

        <Input label="Description" {...register('description')} error={errors.description?.message} required />

        <div className="grid grid-cols-2 gap-3">
          <Select label="Interval type" {...register('interval_type')} error={errors.interval_type?.message} required>
            <option value="hours">Hours (engine time)</option>
            <option value="days">Days (calendar)</option>
            <option value="shots">Shots (mold only)</option>
          </Select>
          <Input label="Interval value" type="number" {...register('interval_value')} error={errors.interval_value?.message} required />
        </div>

        <Select label="Status" {...register('is_active')} error={errors.is_active?.message}>
          <option value="true">Active</option>
          <option value="false">Disabled</option>
        </Select>

        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate(`/maintenance/schedules/${id}`)}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Save changes</Button>
        </div>
      </form>
    </div>
  );
}
```

- [ ] **Step 3: Register routes in `spa/src/routes/maintenanceRoutes.tsx`**

Add lazy imports after `CreateMaintenanceSchedulePage`:

```typescript
const MaintenanceScheduleDetailPage = lazy(() => import('@/pages/maintenance/schedules/detail'));
const EditMaintenanceSchedulePage   = lazy(() => import('@/pages/maintenance/schedules/edit'));
```

Add routes after the existing `/maintenance/schedules/create` route:

```typescript
      <Route path="/maintenance/schedules/:id"
        element={<PermissionGuard permission="maintenance.view"><MaintenanceScheduleDetailPage /></PermissionGuard>} />
      <Route path="/maintenance/schedules/:id/edit"
        element={<PermissionGuard permission="maintenance.schedules.manage"><EditMaintenanceSchedulePage /></PermissionGuard>} />
```

- [ ] **Step 4: Add clickable row + edit/delete actions to `spa/src/pages/maintenance/schedules/index.tsx`**

Add to imports at the top:

```typescript
import { useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Pencil, Trash2 } from 'lucide-react';
```

Inside the component, after the existing hooks, add:

```typescript
  const qc = useQueryClient();

  const deleteMut = useMutation({
    mutationFn: (id: string) => schedulesApi.destroy(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['maintenance', 'schedules'] });
      toast.success('Schedule deleted.');
    },
    onError: () => toast.error('Failed to delete schedule.'),
  });

  const handleDelete = (id: string, description: string) => {
    if (!window.confirm(`Delete schedule "${description}"? This cannot be undone.`)) return;
    deleteMut.mutate(id);
  };
```

Add a new column to the `columns` array at the end:

```typescript
    {
      key: 'actions',
      header: '',
      cell: (r) => can('maintenance.schedules.manage') ? (
        <div className="flex gap-1 justify-end">
          <button
            onClick={(e) => { e.stopPropagation(); navigate(`/maintenance/schedules/${r.id}/edit`); }}
            title="Edit"
            className="p-1 rounded text-muted hover:text-accent hover:bg-elevated transition-colors"
          >
            <Pencil size={13} />
          </button>
          <button
            onClick={(e) => { e.stopPropagation(); handleDelete(r.id, r.description); }}
            title="Delete"
            className="p-1 rounded text-muted hover:text-danger hover:bg-danger/10 transition-colors"
          >
            <Trash2 size={13} />
          </button>
        </div>
      ) : null,
    },
```

Make each row clickable by passing `onRowClick` to `DataTable`:

```typescript
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onRowClick={(r) => navigate(`/maintenance/schedules/${r.id}`)}
          />
```

- [ ] **Step 5: Verify**

1. Navigate to `/maintenance/schedules` → each row has pencil/trash icons at right edge
2. Click a row → navigates to `/maintenance/schedules/:id` detail page
3. Detail page shows target type, target code, interval, last/next dates
4. Edit button → pre-filled form → save → redirects back to detail
5. Delete button → confirm dialog → deletes → redirects to list

- [ ] **Step 6: Commit**

```bash
git add spa/src/routes/maintenanceRoutes.tsx spa/src/pages/maintenance/schedules/
git commit -m "feat: maintenance schedule detail + edit pages — detail view, edit form, delete with list row actions"
```

---

## Task 12: Delete orphaned receive.tsx

**Files:**
- Delete: `spa/src/pages/inventory/receive.tsx`

`spa/src/pages/inventory/receive.tsx` exists as a file but is not imported in any route file or any other component. It's dead code and creates noise.

- [ ] **Step 1: Verify no imports**

```bash
grep -r "inventory/receive" spa/src/
```

Expected output: no results. If any imports are found, investigate before deleting.

- [ ] **Step 2: Delete the file**

```bash
rm spa/src/pages/inventory/receive.tsx
```

- [ ] **Step 3: Verify TypeScript still compiles**

```bash
cd spa && npx tsc --noEmit
```

Expected: no new errors.

- [ ] **Step 4: Commit**

```bash
git add -u spa/src/pages/inventory/receive.tsx
git commit -m "chore: remove orphaned inventory/receive.tsx — no route or import reference"
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] 3 entirely missing sidebar sections (Forecasting, Return Management, Assets) — Tasks 1
- [x] Finance section expanded (COA, Vendors, Statements, Budgets) — Task 2
- [x] Quality section expanded (Dashboard, Specs, NCR Templates, Traceability) — Task 3
- [x] CRM section expanded (Products, Price Agreements, Complaints) — Task 3
- [x] Production/MRP section expanded (Dashboard, OEE, BOMs, Machines, Molds) — Task 4
- [x] HR section expanded (Departments, Positions, Directory, Separations, Adjustments) — Task 5
- [x] Procurement section expanded (Approved Suppliers) — Task 5
- [x] Warehouse, Supply Chain, Maintenance, Admin sections expanded — Task 6
- [x] COA create/edit pages + broken "Add account" button fix — Task 7
- [x] Material issue detail page + missing route — Task 8
- [x] BOM edit page + route + detail edit/delete buttons — Task 9
- [x] Asset edit page + route + detail edit button — Task 10
- [x] Maintenance schedule detail + edit + routes + list edit/delete — Task 11
- [x] Orphaned receive.tsx removed — Task 12

**No placeholder issues** — every step has actual code or exact bash commands. No "TBD" or "implement later."

**Type consistency:**
- `Account`, `CreateAccountData`, `UpdateAccountData` from `@/types/accounting` — used in Tasks 7
- `MaterialIssueSlip`, `MaterialIssueSlipItem` from `@/types/inventory` — used in Task 8
- `Bom`, `CreateBomData` from `@/api/mrp/boms` + `@/types/mrp` — used in Task 9
- `Asset`, `CreateAssetData` from `@/types/assets` — used in Task 10
- `MaintenanceSchedule`, `CreateMaintenanceScheduleData` from `@/types/maintenance` — used in Task 11
- All `id` fields are `string` (HashID) throughout — consistent with project convention

**Icon availability:** All new icons (`TrendingUp`, `RotateCcw`, `Building2`, `BarChart2`, `PieChart`, `Landmark`, `Store`, `Tag`, `MessageSquare`, `ClipboardList`, `GitFork`, `ListTree`, `Cpu`, `Activity`, `Network`, `UserMinus`, `BadgeCheck`, `SlidersHorizontal`) are standard lucide-react exports available in the installed version.
