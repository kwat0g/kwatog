# OGAMI ERP — Module Enhancement & Gap-Fill Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every identified gap across all 17 modules + Quality/Maintenance/Dashboard, upgrade thin UI pages to production-grade, and add high-value new features that strengthen the three business chains for thesis defense.

**Architecture:** Modular monolith stays. Backend is ~100% complete; work is ~70% frontend (UI completeness, interaction depth) and ~30% backend (missing request forms, new endpoints for suggested features). Every task is independently completable and commits cleanly.

**Tech Stack:** Laravel 11 (PHP 8.3) · React 18 + TypeScript + Vite · TanStack Query · React Hook Form + Zod · Tailwind v4 · Recharts · DomPDF · Redis · PostgreSQL 16

---

## AUDIT SUMMARY

| Module | Backend | Frontend | Gap Level |
|--------|---------|----------|-----------|
| Auth / Admin | ✅ Complete | ✅ Complete | None |
| HR | ✅ Complete | ✅ Complete | Minor |
| Attendance | ✅ Complete | ✅ Complete | Minor |
| Leave | ✅ Complete | ✅ Complete | Minor |
| Payroll | ✅ Complete | ✅ Complete | Minor |
| Loans | ✅ Complete | ✅ Complete | Minor |
| Accounting | ✅ Complete | ⚠️ Thin bills/COA UI | Medium |
| Inventory | ✅ Complete | ✅ Complete | Minor |
| Purchasing | ✅ Complete | ✅ Complete | Minor |
| Supply Chain | ✅ Complete | ⚠️ Thin shipments, missing delivery creation form | Medium |
| Production | ✅ Complete | ⚠️ Thin schedule/Gantt | Medium |
| MRP / MRP II | ✅ Complete | ⚠️ Thin plan detail, no material shortage view | Medium |
| CRM | ✅ Complete | ⚠️ No customer CRUD, price agreement create | High |
| B2B Portal | ✅ Complete | ⚠️ Thin dashboards | Medium |
| Forecasting | ✅ Complete | ⚠️ UI functional but no trend charts | Low |
| Return Management | ✅ Complete | ✅ Complete | Minor — missing approval flow UI |
| Assets | ✅ Complete | ⚠️ Thin create/edit form | Low |
| Budgeting | ✅ Complete | ⚠️ Budget-vs-actual thin | Medium |
| Quality | ✅ Complete | ✅ Complete | Minor — CoC PDF print button missing |
| Maintenance | ✅ Complete | ⚠️ No OEE/downtime charts | Medium |
| Dashboard | ✅ Complete | ✅ Complete | Minor |

---

## FILE MAP

### New Files to Create
- `spa/src/pages/crm/customers/create.tsx`
- `spa/src/pages/crm/customers/edit.tsx`
- `spa/src/pages/crm/customers/detail.tsx`
- `spa/src/pages/crm/customers/index.tsx`
- `spa/src/pages/crm/customers/form.tsx`
- `spa/src/pages/crm/price-agreements/create.tsx`
- `spa/src/api/crm/customers.ts`
- `spa/src/pages/supply-chain/shipments/create.tsx`
- `spa/src/pages/supply-chain/shipments/detail.tsx`
- `spa/src/pages/supply-chain/deliveries/create.tsx`
- `spa/src/pages/maintenance/downtime/create.tsx`
- `spa/src/pages/maintenance/oee-chart.tsx`
- `spa/src/components/charts/DowntimeParetoChart.tsx`
- `spa/src/components/charts/OeeGaugeChart.tsx`
- `spa/src/pages/mrp/plans/material-shortage.tsx`
- `spa/src/pages/budgeting/transfers.tsx`
- `spa/src/pages/quality/coc-print.tsx`
- `spa/src/pages/accounting/collections/index.tsx`
- `spa/src/pages/accounting/collections/create.tsx`

### Files to Modify / Enhance
- `spa/src/pages/crm/price-agreements/index.tsx` — add create button + inline price edit
- `spa/src/pages/supply-chain/shipments/index.tsx` — add create + detail link
- `spa/src/pages/production/schedule.tsx` — replace thin Gantt wrapper with full interactive Gantt
- `spa/src/pages/mrp/plans/detail.tsx` — add material shortage table + MRP output breakdown
- `spa/src/pages/budgeting/budget-vs-actual.tsx` — add bar chart variance visualization
- `spa/src/pages/budgeting/detail.tsx` — add line item inline edit + revision history
- `spa/src/pages/assets/create.tsx` — complete the create form (currently 105 lines / stub)
- `spa/src/pages/assets/detail.tsx` — add QR code display + depreciation schedule table
- `spa/src/pages/return-management/detail.tsx` — add approve/reject action buttons + status timeline
- `spa/src/pages/maintenance/schedules/index.tsx` — add due-soon highlighting + calendar view toggle
- `spa/src/pages/accounting/bills/create.tsx` — fix line-item total calculation UX
- `spa/src/pages/accounting/coa/index.tsx` — add account balance column + drill-down to ledger
- `spa/src/pages/portal/customer/dashboard.tsx` — add real KPI cards (open orders, outstanding balance)
- `spa/src/pages/portal/supplier/dashboard.tsx` — add real KPI cards (open POs, pending invoices)
- `api/app/Modules/CRM/Controllers/CustomerController.php` — verify customers CRUD is complete
- `api/app/Modules/CRM/routes.php` — ensure `/customers` routes exist
- `api/app/Modules/Accounting/Controllers/BudgetController.php` — add budget transfer endpoint
- `api/app/Modules/Quality/Controllers/InspectionController.php` — verify CoC download route
- `api/app/Modules/SupplyChain/Requests/CreateShipmentRequest.php` — verify fields complete

---

## TASK 1: CRM — Customer Master CRUD

**Why:** CRM module has Sales Orders, Complaints, Price Agreements — but no Customer list/create/edit pages. Customers exist in DB (Accounting uses them too) but there's no management UI. This breaks Chain 1.

**Files:**
- Create: `spa/src/api/crm/customers.ts`
- Create: `spa/src/pages/crm/customers/index.tsx`
- Create: `spa/src/pages/crm/customers/form.tsx`
- Create: `spa/src/pages/crm/customers/create.tsx`
- Create: `spa/src/pages/crm/customers/edit.tsx`
- Create: `spa/src/pages/crm/customers/detail.tsx`
- Modify: `spa/src/App.tsx` (or routes file) — add customer routes

- [ ] **Step 1: Verify backend customer routes exist**

```bash
grep -n "customers" /home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/routes.php
```
Expected: routes for index, store, show, update, destroy under `/crm/customers`. If missing, add them (see Step 1b).

- [ ] **Step 1b (if routes missing): Add CRM customer routes**

Read `/home/kwat0g/Desktop/kwatog/api/app/Modules/CRM/routes.php` and add:
```php
Route::apiResource('customers', CustomerController::class);
```
inside the existing `Route::group` with `auth:sanctum` middleware.

- [ ] **Step 2: Create API client**

Create `spa/src/api/crm/customers.ts`:
```typescript
import { client } from '@/api/client';
import type { Customer, Paginated } from '@/types/crm';

export interface CustomerListParams {
  page?: number;
  per_page?: number;
  search?: string;
  status?: string;
}

export interface CreateCustomerData {
  name: string;
  code: string;
  contact_person?: string;
  email?: string;
  phone?: string;
  address?: string;
  payment_terms_days?: number;
  credit_limit?: string;
}

export const customersApi = {
  list: (params?: CustomerListParams) =>
    client.get<Paginated<Customer>>('/crm/customers', { params }).then(r => r.data),
  show: (id: string) =>
    client.get<{ data: Customer }>(`/crm/customers/${id}`).then(r => r.data.data),
  create: (data: CreateCustomerData) =>
    client.post<{ data: Customer }>('/crm/customers', data).then(r => r.data.data),
  update: (id: string, data: Partial<CreateCustomerData>) =>
    client.put<{ data: Customer }>(`/crm/customers/${id}`, data).then(r => r.data.data),
  delete: (id: string) =>
    client.delete(`/crm/customers/${id}`),
};
```

- [ ] **Step 3: Create shared form component**

Create `spa/src/pages/crm/customers/form.tsx`:
```typescript
import { useFormContext } from 'react-hook-form';
import { FormField } from '@/components/ui/FormField';
import { Input } from '@/components/ui/Input';

export function CustomerForm() {
  const { register, formState: { errors } } = useFormContext();

  return (
    <div className="grid grid-cols-2 gap-4">
      <FormField label="Customer Name" error={errors.name?.message as string} required>
        <Input {...register('name')} placeholder="Toyota Motor Philippines" />
      </FormField>
      <FormField label="Customer Code" error={errors.code?.message as string} required>
        <Input {...register('code')} placeholder="TMP-001" className="font-mono" />
      </FormField>
      <FormField label="Contact Person" error={errors.contact_person?.message as string}>
        <Input {...register('contact_person')} />
      </FormField>
      <FormField label="Email" error={errors.email?.message as string}>
        <Input {...register('email')} type="email" />
      </FormField>
      <FormField label="Phone" error={errors.phone?.message as string}>
        <Input {...register('phone')} />
      </FormField>
      <FormField label="Payment Terms (days)" error={errors.payment_terms_days?.message as string}>
        <Input {...register('payment_terms_days')} type="number" className="font-mono" />
      </FormField>
      <FormField label="Credit Limit (₱)" error={errors.credit_limit?.message as string}>
        <Input {...register('credit_limit')} type="number" step="0.01" className="font-mono" />
      </FormField>
      <FormField label="Address" error={errors.address?.message as string} className="col-span-2">
        <Input {...register('address')} />
      </FormField>
    </div>
  );
}
```

- [ ] **Step 4: Create customer list page**

Create `spa/src/pages/crm/customers/index.tsx` — full list page with search, status filter, DataTable. Follow PATTERNS.md list page template exactly. Columns: Customer Code (font-mono), Name, Contact, Email, Payment Terms, Credit Limit (NumCell), Sales Orders count. Row click → `/crm/customers/:id`.

- [ ] **Step 5: Create customer create page**

Create `spa/src/pages/crm/customers/create.tsx` — FormProvider + zod schema + CustomerForm + submit to `customersApi.create`. On success toast + navigate to detail page.

Schema:
```typescript
const schema = z.object({
  name: z.string().min(1).max(200),
  code: z.string().min(1).max(50),
  contact_person: z.string().optional(),
  email: z.string().email().optional().or(z.literal('')),
  phone: z.string().optional(),
  address: z.string().optional(),
  payment_terms_days: z.coerce.number().int().min(0).max(365).optional(),
  credit_limit: z.string().regex(/^\d+\.?\d{0,2}$/).optional(),
});
```

- [ ] **Step 6: Create customer edit page**

Create `spa/src/pages/crm/customers/edit.tsx` — same as create but prefills from `customersApi.show(id)` and calls `customersApi.update(id, data)`.

- [ ] **Step 7: Create customer detail page**

Create `spa/src/pages/crm/customers/detail.tsx` — detail with:
1. Header: customer name, code chip, edit button
2. Info grid: contact, email, phone, payment terms, credit limit
3. Tabs: "Sales Orders" (linked list), "Price Agreements" (linked list), "Invoices" (linked list), "Complaints" (linked list)
4. Each tab shows a mini DataTable with 5 most recent + "View all" link

- [ ] **Step 8: Register routes**

In your SPA router file (check `spa/src/App.tsx` or `spa/src/routes/`), add:
```tsx
<Route path="/crm/customers" element={<React.lazy(() => import('@/pages/crm/customers/index'))> />} />
<Route path="/crm/customers/create" element={...create page...} />
<Route path="/crm/customers/:id" element={...detail page...} />
<Route path="/crm/customers/:id/edit" element={...edit page...} />
```
All wrapped in `AuthGuard` + `ModuleGuard module="crm"` + `PermissionGuard`.

- [ ] **Step 9: Add to CRM sidebar nav**

Find where CRM nav items are defined (likely `spa/src/components/layout/Sidebar.tsx` or a nav config file). Add "Customers" item with `/crm/customers` path.

- [ ] **Step 10: Commit**
```bash
git add spa/src/api/crm/customers.ts spa/src/pages/crm/customers/
git commit -m "feat: CRM customer master CRUD pages — list, create, edit, detail"
```

---

## TASK 2: CRM — Price Agreement Create/Edit

**Why:** Price agreements page is read-only (87 lines). No way to create or modify price agreements from UI. Critical for SO pricing validation.

**Files:**
- Create: `spa/src/pages/crm/price-agreements/create.tsx`
- Modify: `spa/src/pages/crm/price-agreements/index.tsx` — add "New" button + edit action

- [ ] **Step 1: Check existing priceAgreementsApi**

Read `spa/src/api/crm/priceAgreements.ts` — check if `create` and `update` methods exist. If not, add:
```typescript
create: (data: CreatePriceAgreementData) =>
  client.post<{ data: PriceAgreement }>('/crm/price-agreements', data).then(r => r.data.data),
update: (id: string, data: Partial<CreatePriceAgreementData>) =>
  client.put<{ data: PriceAgreement }>(`/crm/price-agreements/${id}`, data).then(r => r.data.data),
```

- [ ] **Step 2: Create price agreement form page**

Create `spa/src/pages/crm/price-agreements/create.tsx`:

Schema:
```typescript
const schema = z.object({
  product_id: z.string().min(1, 'Select a product'),
  customer_id: z.string().min(1, 'Select a customer'),
  agreed_price: z.string().regex(/^\d+\.?\d{0,2}$/, 'Valid price required'),
  min_quantity: z.coerce.number().int().min(1),
  effective_date: z.string().min(1),
  expiry_date: z.string().optional(),
});
```

Fields: Product (searchable select from `/crm/products`), Customer (searchable select from `/crm/customers`), Agreed Price (₱ prefix, font-mono), Min Quantity, Effective Date, Expiry Date (optional).

- [ ] **Step 3: Add "New Price Agreement" button to index**

Modify `spa/src/pages/crm/price-agreements/index.tsx`:
- Add `<Button onClick={() => navigate('/crm/price-agreements/create')}>New Price Agreement</Button>` to PageHeader actions
- Add an edit icon column that opens edit modal or navigates to edit

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/crm/price-agreements/
git commit -m "feat: CRM price agreement create form"
```

---

## TASK 3: Supply Chain — Shipment Create + Detail Pages

**Why:** Shipments list is 79 lines (functional list) but no way to create a new shipment or view detail. Import shipments are a core Chain 2 step.

**Files:**
- Create: `spa/src/pages/supply-chain/shipments/create.tsx`
- Create: `spa/src/pages/supply-chain/shipments/detail.tsx`
- Modify: `spa/src/pages/supply-chain/shipments/index.tsx` — add row click + create button

- [ ] **Step 1: Verify backend shipment create request fields**

Read `api/app/Modules/SupplyChain/Requests/CreateShipmentRequest.php` to see all required fields.

- [ ] **Step 2: Create shipment form**

Create `spa/src/pages/supply-chain/shipments/create.tsx`.

Schema (adapt based on request fields found in step 1):
```typescript
const schema = z.object({
  purchase_order_id: z.string().min(1, 'Select a PO'),
  supplier_invoice_no: z.string().optional(),
  bill_of_lading_no: z.string().optional(),
  origin_port: z.string().optional(),
  destination_port: z.string().optional(),
  estimated_arrival: z.string().optional(),
  freight_cost: z.string().optional(),
  insurance_cost: z.string().optional(),
  notes: z.string().optional(),
});
```

Fields: PO (searchable select from `/purchasing/purchase-orders?status=approved`), Supplier Invoice #, Bill of Lading #, Origin Port, Destination Port, ETA, Freight Cost, Insurance Cost, Notes.

- [ ] **Step 3: Create shipment detail page**

Create `spa/src/pages/supply-chain/shipments/detail.tsx`:
1. Header: shipment number (font-mono), status chip, actions (Update Status button)
2. Info grid: PO link, supplier, origin/destination ports, ETA, actual arrival
3. Costs panel: freight, insurance, total landed cost
4. Documents section: list of attached ShipmentDocuments (type chip + upload button)
5. Status timeline: ordered → shipped → in_transit → customs → cleared → received

- [ ] **Step 4: Update shipments index**

Modify `spa/src/pages/supply-chain/shipments/index.tsx`:
- Add `<Button>New Shipment</Button>` to PageHeader
- Make rows clickable → navigate to `/supply-chain/shipments/:id`
- Add status filter

- [ ] **Step 5: Commit**
```bash
git add spa/src/pages/supply-chain/shipments/
git commit -m "feat: supply chain shipment create + detail pages"
```

---

## TASK 4: Supply Chain — Delivery Create Form

**Why:** `spa/src/pages/supply-chain/deliveries/` has index and detail but no create page. Deliveries are triggered by QC pass (auto), but operations staff need to manually create or adjust deliveries.

**Files:**
- Create: `spa/src/pages/supply-chain/deliveries/create.tsx`
- Modify: `spa/src/pages/supply-chain/deliveries/index.tsx` — add create button

- [ ] **Step 1: Verify delivery create backend**

Read `api/app/Modules/SupplyChain/Requests/CreateDeliveryRequest.php` for all fields.

- [ ] **Step 2: Create delivery form**

Create `spa/src/pages/supply-chain/deliveries/create.tsx`.

Schema:
```typescript
const schema = z.object({
  sales_order_id: z.string().min(1),
  vehicle_id: z.string().optional(),
  scheduled_date: z.string().min(1),
  delivery_address: z.string().min(1),
  notes: z.string().optional(),
  items: z.array(z.object({
    inventory_item_id: z.string().min(1),
    quantity: z.coerce.number().positive(),
  })).min(1),
});
```

Fields: Sales Order (select), Vehicle (select from fleet), Scheduled Date, Delivery Address, line items (item + qty with add/remove rows).

- [ ] **Step 3: Commit**
```bash
git add spa/src/pages/supply-chain/deliveries/create.tsx
git commit -m "feat: supply chain delivery create form"
```

---

## TASK 5: Production — Full Gantt Schedule Page

**Why:** `production/schedule.tsx` (135 lines) imports a `GanttChart` component but the page itself is thin — it calls the scheduler API and passes data down, but lacks: conflict display, machine filter, date range picker, drag-to-reschedule context. This is a thesis showcase feature.

**Files:**
- Modify: `spa/src/pages/production/schedule.tsx` — full interactive Gantt
- Check/modify: `spa/src/components/production/GanttChart.tsx` — verify it renders properly

- [ ] **Step 1: Read existing GanttChart component**

```bash
cat spa/src/components/production/GanttChart.tsx
```
Understand current props interface and rendering.

- [ ] **Step 2: Enhance schedule page**

Rewrite `spa/src/pages/production/schedule.tsx` to include:

1. **Toolbar**: date range picker (week/month), machine filter multi-select, "Run MRP Scheduler" button, "Confirm Schedule" button
2. **Conflict alerts panel**: if `conflicts.length > 0`, show amber banner listing conflicts (machine, time slot, work order)
3. **Gantt chart**: machine rows × time columns, WO blocks colored by status, hover tooltip showing WO#, product, qty, start/end
4. **Legend**: status color legend below chart
5. **Action bar**: if proposed schedule exists, show "Confirm All" + "Discard" buttons

Full example structure:
```typescript
export default function ProductionSchedulePage() {
  const [dateRange, setDateRange] = useState({ start: today, end: today + 7d });
  const [machineFilter, setMachineFilter] = useState<string[]>([]);

  const snapshot = useQuery({
    queryKey: ['mrp', 'scheduler', 'snapshot', dateRange, machineFilter],
    queryFn: () => schedulerApi.snapshot({ ...dateRange, machine_ids: machineFilter }),
  });

  const runScheduler = useMutation({ mutationFn: schedulerApi.run, ... });
  const confirmSchedule = useMutation({ mutationFn: schedulerApi.confirm, ... });

  return (
    <>
      <PageHeader title="Production Schedule">
        <DateRangePicker value={dateRange} onChange={setDateRange} />
        <MachineMultiSelect value={machineFilter} onChange={setMachineFilter} />
        <Button onClick={() => runScheduler.mutate()} disabled={!canRun}>Run Scheduler</Button>
      </PageHeader>
      {snapshot.data?.conflicts.length > 0 && <ConflictBanner conflicts={snapshot.data.conflicts} />}
      <GanttChart
        rows={snapshot.data?.schedule ?? []}
        dateRange={dateRange}
        isLoading={snapshot.isLoading}
      />
      {snapshot.data?.proposed && (
        <div className="flex gap-2 mt-4">
          <Button variant="primary" onClick={() => confirmSchedule.mutate()}>Confirm Schedule</Button>
          <Button variant="ghost" onClick={() => runScheduler.reset()}>Discard</Button>
        </div>
      )}
    </>
  );
}
```

- [ ] **Step 3: Commit**
```bash
git add spa/src/pages/production/schedule.tsx
git commit -m "feat: production schedule page — full Gantt with machine filter, conflict alerts, confirm flow"
```

---

## TASK 6: MRP — Material Shortage View in Plan Detail

**Why:** `mrp/plans/detail.tsx` (133 lines) shows plan status and a rerun button — but doesn't show the actual MRP output: what materials are short, by how much, and which purchase requests were auto-generated. This is the core MRP value prop.

**Files:**
- Modify: `spa/src/pages/mrp/plans/detail.tsx` — add shortage table + PR links
- Create: `spa/src/pages/mrp/plans/material-shortage.tsx` — dedicated shortage view

- [ ] **Step 1: Check MRP plan API response**

Read `api/app/Modules/MRP/Resources/` to see what fields are returned for a plan. Also check `spa/src/api/mrp/mrpPlans.ts`.

- [ ] **Step 2: Enhance plan detail page**

Modify `spa/src/pages/mrp/plans/detail.tsx` to add after the existing header:

**Summary Cards Row:**
- Total demand (units)
- Materials with shortage (count)  
- Auto-generated PRs (count)
- Coverage date

**Shortage Table:**
Columns: Item Code (font-mono), Description, Required Qty, On Hand, Shortfall (red if > 0), Auto-PR# (link to purchasing/purchase-requests/:id), Status chip

```typescript
// Add to existing page
const { data: shortages } = useQuery({
  queryKey: ['mrp', 'plans', id, 'shortages'],
  queryFn: () => mrpPlansApi.shortages(id!),
  enabled: !!id && !!data,
});

// In JSX, after existing plan info:
<Panel title="Material Shortages" className="mt-4">
  {shortages?.length === 0 ? (
    <EmptyState message="No material shortages — all demand covered." />
  ) : (
    <DataTable
      data={shortages ?? []}
      columns={shortageColumns}
      isLoading={!shortages}
    />
  )}
</Panel>
```

- [ ] **Step 3: Add shortages endpoint to mrpPlansApi if missing**

In `spa/src/api/mrp/mrpPlans.ts`, add:
```typescript
shortages: (id: string) =>
  client.get<{ data: MrpShortage[] }>(`/mrp/plans/${id}/shortages`).then(r => r.data.data),
```

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/mrp/plans/detail.tsx spa/src/api/mrp/mrpPlans.ts
git commit -m "feat: MRP plan detail — material shortage table with PR links"
```

---

## TASK 7: Maintenance — Downtime Pareto Chart + OEE Gauge

**Why:** `maintenance/downtime/index.tsx` exists but has no chart visualization. `maintenance/machine-health/index.tsx` (242 lines) shows readings. There's no Pareto chart of downtime causes — which is the #1 maintenance KPI for IATF 16949.

**Files:**
- Create: `spa/src/components/charts/DowntimeParetoChart.tsx`
- Create: `spa/src/components/charts/OeeGaugeChart.tsx`
- Modify: `spa/src/pages/maintenance/downtime/index.tsx` — add Pareto chart above list
- Modify: `spa/src/pages/production/oee.tsx` — add OEE gauge

- [ ] **Step 1: Create Pareto chart component**

Create `spa/src/components/charts/DowntimeParetoChart.tsx`:
```typescript
import { ComposedChart, Bar, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';

interface ParetoEntry {
  category: string;
  hours: number;
  cumulative_pct: number;
}

interface Props {
  data: ParetoEntry[];
}

export function DowntimeParetoChart({ data }: Props) {
  return (
    <ResponsiveContainer width="100%" height={280}>
      <ComposedChart data={data} margin={{ top: 8, right: 24, left: 0, bottom: 8 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
        <XAxis dataKey="category" tick={{ fontSize: 12 }} />
        <YAxis yAxisId="left" tick={{ fontSize: 12 }} />
        <YAxis yAxisId="right" orientation="right" unit="%" domain={[0, 100]} tick={{ fontSize: 12 }} />
        <Tooltip
          contentStyle={{ background: 'var(--surface-raised)', border: '1px solid var(--border)', borderRadius: 6 }}
        />
        <Legend />
        <Bar yAxisId="left" dataKey="hours" fill="var(--color-danger)" name="Downtime (hrs)" radius={[4,4,0,0]} />
        <Line yAxisId="right" type="monotone" dataKey="cumulative_pct" stroke="var(--color-warning)" name="Cumulative %" dot={false} strokeWidth={2} />
      </ComposedChart>
    </ResponsiveContainer>
  );
}
```

- [ ] **Step 2: Create OEE gauge**

Create `spa/src/components/charts/OeeGaugeChart.tsx`:
```typescript
import { RadialBarChart, RadialBar, PolarAngleAxis, ResponsiveContainer } from 'recharts';

interface Props {
  availability: number; // 0-100
  performance: number;
  quality: number;
  oee: number;
}

export function OeeGaugeChart({ availability, performance, quality, oee }: Props) {
  const color = oee >= 85 ? 'var(--color-success)' : oee >= 60 ? 'var(--color-warning)' : 'var(--color-danger)';
  const data = [{ value: oee, fill: color }];
  return (
    <div className="relative flex flex-col items-center">
      <ResponsiveContainer width={200} height={120}>
        <RadialBarChart cx="50%" cy="90%" innerRadius="60%" outerRadius="100%" startAngle={180} endAngle={0} data={data}>
          <PolarAngleAxis type="number" domain={[0, 100]} angleAxisId={0} tick={false} />
          <RadialBar background dataKey="value" angleAxisId={0} />
        </RadialBarChart>
      </ResponsiveContainer>
      <div className="absolute bottom-0 text-center">
        <div className="text-3xl font-mono font-medium tabular-nums">{oee.toFixed(1)}%</div>
        <div className="text-xs text-muted">OEE</div>
      </div>
      <div className="mt-2 grid grid-cols-3 gap-4 text-center text-sm">
        <div><div className="font-mono tabular-nums">{availability.toFixed(1)}%</div><div className="text-muted text-xs">Availability</div></div>
        <div><div className="font-mono tabular-nums">{performance.toFixed(1)}%</div><div className="text-muted text-xs">Performance</div></div>
        <div><div className="font-mono tabular-nums">{quality.toFixed(1)}%</div><div className="text-muted text-xs">Quality</div></div>
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Add Pareto to downtime page**

Modify `spa/src/pages/maintenance/downtime/index.tsx`. Read the existing file first. Add before the DataTable:
```typescript
const pareto = useQuery({
  queryKey: ['maintenance', 'downtime', 'pareto', filters],
  queryFn: () => maintenanceApi.downtimePareto(filters),
});
// Then in JSX:
{pareto.data && <DowntimeParetoChart data={pareto.data} />}
```

- [ ] **Step 4: Add OEE gauge to OEE page**

Modify `spa/src/pages/production/oee.tsx`. Read existing (351 lines). Add OeeGaugeChart for currently-selected machine/period. Place it prominently at top.

- [ ] **Step 5: Verify API method exists**

Check `spa/src/api/maintenance/downtimeAnalytics.ts` — add `paretoByCategory()` if missing:
```typescript
export const downtimeAnalyticsApi = {
  ...(existing),
  paretoByCategory: (params?: { machine_id?: string; start?: string; end?: string }) =>
    client.get<{ data: ParetoEntry[] }>('/maintenance/downtime/pareto', { params }).then(r => r.data.data),
};
```

- [ ] **Step 6: Commit**
```bash
git add spa/src/components/charts/ spa/src/pages/maintenance/ spa/src/pages/production/oee.tsx
git commit -m "feat: maintenance downtime Pareto chart + OEE gauge component"
```

---

## TASK 8: Budgeting — Variance Bar Chart + Budget Transfers UI

**Why:** `budgeting/budget-vs-actual.tsx` (160 lines) likely shows a table but no chart. Budget transfers exist in backend (`BudgetTransferController`, `BudgetTransferService`) but there's no frontend page to create or view transfers.

**Files:**
- Modify: `spa/src/pages/budgeting/budget-vs-actual.tsx` — add variance bar chart
- Create: `spa/src/pages/budgeting/transfers.tsx` — budget transfer list + create
- Modify: `spa/src/pages/budgeting/detail.tsx` — add revision history tab + inline line item edit

- [ ] **Step 1: Read budget-vs-actual current state**

```bash
cat spa/src/pages/budgeting/budget-vs-actual.tsx
```

- [ ] **Step 2: Add variance chart**

After reading, modify `spa/src/pages/budgeting/budget-vs-actual.tsx` to add above the table:
```typescript
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, Cell } from 'recharts';

// Map budget data to chart format:
const chartData = data?.line_items?.map(item => ({
  name: item.account_name,
  budget: Number(item.budget_amount),
  actual: Number(item.actual_amount),
  variance: Number(item.actual_amount) - Number(item.budget_amount),
}));

// Chart:
<ResponsiveContainer width="100%" height={240}>
  <BarChart data={chartData}>
    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
    <XAxis dataKey="name" tick={{ fontSize: 11 }} />
    <YAxis tick={{ fontSize: 11 }} tickFormatter={v => `₱${(v/1000).toFixed(0)}k`} />
    <Tooltip formatter={(v: number) => `₱${v.toLocaleString()}`} />
    <Legend />
    <Bar dataKey="budget" fill="var(--color-info)" name="Budget" radius={[4,4,0,0]} />
    <Bar dataKey="actual" name="Actual" radius={[4,4,0,0]}>
      {chartData?.map((entry, index) => (
        <Cell key={index} fill={entry.actual > entry.budget ? 'var(--color-danger)' : 'var(--color-success)'} />
      ))}
    </Bar>
  </BarChart>
</ResponsiveContainer>
```

- [ ] **Step 3: Create budget transfers page**

Create `spa/src/pages/budgeting/transfers.tsx` — list of budget transfers with: Transfer #, From Account, To Account, Amount, Status, Date. "New Transfer" button opens a form modal (or navigates to create page):

Transfer form schema:
```typescript
const schema = z.object({
  budget_id: z.string().min(1),
  from_line_item_id: z.string().min(1),
  to_line_item_id: z.string().min(1),
  amount: z.string().regex(/^\d+\.?\d{0,2}$/),
  reason: z.string().min(10),
});
```

- [ ] **Step 4: Add transfers link to budgeting nav**

Add "Transfers" to sidebar under /budgeting.

- [ ] **Step 5: Commit**
```bash
git add spa/src/pages/budgeting/
git commit -m "feat: budgeting variance chart + budget transfers UI"
```

---

## TASK 9: Assets — Complete Create/Edit Form + QR Code Display

**Why:** `assets/create.tsx` (105 lines) is a stub. `assets/detail.tsx` exists but likely missing QR code display and depreciation schedule table (both are implemented in backend: `AssetQrCodeService`, `DepreciationService`).

**Files:**
- Modify: `spa/src/pages/assets/create.tsx` — complete the form
- Modify: `spa/src/pages/assets/detail.tsx` — add QR code + depreciation table

- [ ] **Step 1: Read existing create page**

```bash
cat spa/src/pages/assets/create.tsx
cat spa/src/pages/assets/detail.tsx
```

- [ ] **Step 2: Complete create form**

Rewrite `spa/src/pages/assets/create.tsx` with full schema:
```typescript
const schema = z.object({
  name: z.string().min(1).max(200),
  asset_no: z.string().optional(),
  category: z.enum(['machinery', 'equipment', 'vehicle', 'furniture', 'it', 'building', 'land', 'other']),
  description: z.string().optional(),
  purchase_date: z.string().min(1),
  purchase_cost: z.string().regex(/^\d+\.?\d{0,2}$/),
  useful_life_months: z.coerce.number().int().min(1).max(600),
  salvage_value: z.string().regex(/^\d+\.?\d{0,2}$/),
  location: z.string().optional(),
  serial_no: z.string().optional(),
  vendor_id: z.string().optional(),
  depreciation_method: z.enum(['straight_line', 'declining_balance']),
  account_id: z.string().optional(),
});
```

Fields: Name, Asset No (auto if blank), Category (select with enum values), Description, Purchase Date, Purchase Cost, Useful Life (months), Salvage Value, Location, Serial No, Vendor (select), Depreciation Method, GL Account.

- [ ] **Step 3: Add QR code + depreciation schedule to detail**

Read `spa/src/pages/assets/detail.tsx`. Add:

**QR Code Section** (in a Panel):
```typescript
const qrCode = useQuery({
  queryKey: ['assets', id, 'qr'],
  queryFn: () => assetsApi.qrCode(id!),
  enabled: !!id,
});
// Display as <img src={qrCode.data?.svg_url} /> with a "Print QR" button
```

**Depreciation Schedule Table** (separate Panel):
Columns: Period (Month-Year), Opening BV, Depreciation Expense, Closing BV. Load from `assetsApi.depreciationSchedule(id)`.

- [ ] **Step 4: Verify assetsApi methods**

Read `spa/src/api/assets.ts`. Add if missing:
```typescript
qrCode: (id: string) => client.get<{ data: { svg_url: string; qr_code: string } }>(`/assets/${id}/qr`).then(r => r.data.data),
depreciationSchedule: (id: string) => client.get<{ data: DepreciationRow[] }>(`/assets/${id}/depreciation-schedule`).then(r => r.data.data),
dispose: (id: string, data: { disposal_date: string; disposal_value: string; notes?: string }) =>
  client.post<{ data: Asset }>(`/assets/${id}/dispose`, data).then(r => r.data.data),
```

- [ ] **Step 5: Commit**
```bash
git add spa/src/pages/assets/ spa/src/api/assets.ts
git commit -m "feat: assets complete create form + QR code display + depreciation schedule"
```

---

## TASK 10: Quality — Certificate of Conformance Print Button

**Why:** `CoCService` exists in backend but there's no UI trigger for it. CoC is a critical IATF 16949 document — every outgoing shipment needs one. It should be generatable from Delivery detail or Outgoing Inspection detail.

**Files:**
- Modify: `spa/src/pages/quality/inspections/detail.tsx` — add "Generate CoC" button for outgoing inspections
- Modify: `spa/src/pages/supply-chain/deliveries/detail.tsx` — add "Print CoC" button

- [ ] **Step 1: Check CoC API route**

```bash
grep -rn "CoC\|coc\|certificate" /home/kwat0g/Desktop/kwatog/api/app/Modules/Quality/routes.php
```

- [ ] **Step 2: Add CoC endpoint to quality API**

In `spa/src/api/quality/inspections.ts`, add:
```typescript
generateCoC: (id: string) =>
  client.get(`/quality/inspections/${id}/coc`, { responseType: 'blob' }).then(r => {
    const url = URL.createObjectURL(r.data);
    window.open(url, '_blank');
  }),
```

- [ ] **Step 3: Add CoC button to inspection detail**

Read `spa/src/pages/quality/inspections/detail.tsx`. For inspections where `stage === 'outgoing'` and `status === 'passed'`, add a "Generate CoC" button in the PageHeader actions:
```typescript
{inspection?.stage === 'outgoing' && inspection?.status === 'passed' && (
  <Button
    variant="outline"
    onClick={() => inspectionsApi.generateCoC(id!)}
    loading={isGeneratingCoC}
  >
    <Download className="h-4 w-4 mr-2" />
    Certificate of Conformance
  </Button>
)}
```

- [ ] **Step 4: Add CoC button to delivery detail**

Similarly in `spa/src/pages/supply-chain/deliveries/detail.tsx`, add a "Print CoC" button that links the delivery's inspection CoC.

- [ ] **Step 5: Commit**
```bash
git add spa/src/pages/quality/inspections/detail.tsx spa/src/pages/supply-chain/deliveries/detail.tsx
git commit -m "feat: quality CoC print button on outgoing inspection + delivery detail"
```

---

## TASK 11: Return Management — Approval Flow UI

**Why:** Return requests exist with full backend (`ReturnRequestService`, status enums) but `detail.tsx` likely has no approve/reject action buttons. RMA approval is a key Chain 1 touchpoint.

**Files:**
- Modify: `spa/src/pages/return-management/detail.tsx` — add status timeline + approve/reject

- [ ] **Step 1: Read existing detail page**

```bash
cat spa/src/pages/return-management/detail.tsx
```

- [ ] **Step 2: Add approval actions**

Add to `spa/src/pages/return-management/detail.tsx`:

```typescript
const approve = useMutation({
  mutationFn: () => returnManagementApi.approve(id!),
  onSuccess: () => { qc.invalidateQueries({ queryKey: ['returns', id] }); toast.success('Return approved'); },
  onError: () => toast.error('Failed to approve'),
});
const reject = useMutation({
  mutationFn: (reason: string) => returnManagementApi.reject(id!, reason),
  onSuccess: () => { qc.invalidateQueries({ queryKey: ['returns', id] }); toast.success('Return rejected'); },
  onError: () => toast.error('Failed to reject'),
});

// In PageHeader actions, conditional on status:
{rma?.status === 'pending' && can('returns.approve') && (
  <>
    <Button variant="primary" onClick={() => approve.mutate()} loading={approve.isPending}>Approve</Button>
    <Button variant="danger" onClick={() => setShowRejectModal(true)}>Reject</Button>
  </>
)}
```

Add a status timeline strip (similar to other detail pages) showing: Submitted → Under Review → Approved/Rejected → Processed.

- [ ] **Step 3: Verify returnManagementApi has approve/reject**

Read `spa/src/api/returnManagement.ts`. Add if missing:
```typescript
approve: (id: string) => client.post(`/returns/${id}/approve`),
reject: (id: string, reason: string) => client.post(`/returns/${id}/reject`, { reason }),
```

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/return-management/detail.tsx spa/src/api/returnManagement.ts
git commit -m "feat: return management approval flow — approve/reject actions + status timeline"
```

---

## TASK 12: Accounting — Collections List + COA Ledger Drill-Down

**Why:** `AccountController` has collections but there's no `accounting/collections/` frontend page. Collections (customer payments) are critical for AR aging and cash flow view. Also, COA page needs a balance column and ledger drill-down to be useful.

**Files:**
- Create: `spa/src/pages/accounting/collections/index.tsx`
- Create: `spa/src/pages/accounting/collections/create.tsx`
- Modify: `spa/src/pages/accounting/coa/index.tsx` — add balance column + drill-down

- [ ] **Step 1: Check collections API**

Read `spa/src/api/accounting/accounts.ts` or check for a collections endpoint. Grep:
```bash
grep -rn "collection" /home/kwat0g/Desktop/kwatog/api/app/Modules/Accounting/routes.php
```

- [ ] **Step 2: Create collections list page**

Create `spa/src/pages/accounting/collections/index.tsx` — list of customer payments with columns: Collection #, Invoice # (link), Customer, Amount, Payment Method, Collection Date, Status chip. "New Collection" button.

- [ ] **Step 3: Create collection form**

Create `spa/src/pages/accounting/collections/create.tsx`.

Schema:
```typescript
const schema = z.object({
  invoice_id: z.string().min(1),
  amount: z.string().regex(/^\d+\.?\d{0,2}$/),
  payment_method: z.enum(['cash', 'bank_transfer', 'check', 'online']),
  collection_date: z.string().min(1),
  reference_no: z.string().optional(),
  notes: z.string().optional(),
});
```

- [ ] **Step 4: Enhance COA page**

Read `spa/src/pages/accounting/coa/index.tsx`. Add:
- "Balance" column (debit/credit formatted, color-coded by normal balance)
- Row click → opens a ledger view (or navigates to `/accounting/accounts/:id/ledger`)
- "Add Account" button for users with `accounting.accounts.create` permission

- [ ] **Step 5: Commit**
```bash
git add spa/src/pages/accounting/collections/ spa/src/pages/accounting/coa/index.tsx
git commit -m "feat: accounting collections pages + COA balance + ledger drill-down"
```

---

## TASK 13: B2B Portal — Real KPI Dashboards

**Why:** Customer and supplier portal dashboards (141 lines each) are thin placeholder pages. They need real data cards to be demo-worthy.

**Files:**
- Modify: `spa/src/pages/portal/customer/dashboard.tsx`
- Modify: `spa/src/pages/portal/supplier/dashboard.tsx`

- [ ] **Step 1: Read existing portal dashboards**

```bash
cat spa/src/pages/portal/customer/dashboard.tsx
cat spa/src/pages/portal/supplier/dashboard.tsx
```

- [ ] **Step 2: Enhance customer portal dashboard**

Rewrite `spa/src/pages/portal/customer/dashboard.tsx` with:

**KPI Cards Row:**
- Open Sales Orders (count + ₱ value)
- Pending Deliveries (count + nearest ETA)
- Outstanding Balance (₱ amount, color red if > credit limit)
- Recent Complaints (open count)

**Quick Links:**
- "View My Orders" → `/portal/customer/orders`
- "Track Deliveries" → `/portal/customer/deliveries`
- "View Invoices" → `/portal/customer/invoices`
- "Submit Complaint" → `/portal/customer/complaints`

**Recent Activity Table:**
Last 5 transactions (order, delivery, invoice) with date, type chip, amount.

- [ ] **Step 3: Enhance supplier portal dashboard**

Rewrite `spa/src/pages/portal/supplier/dashboard.tsx` with:

**KPI Cards:**
- Open Purchase Orders (count + ₱ value)
- Pending Shipment Confirmations
- Outstanding Invoices (₱ total)
- Performance Rating (from SupplierPerformanceSnapshot)

**Quick Links:**
- "View My POs" → `/portal/supplier/purchase-orders`
- "My Invoices" → `/portal/supplier/invoices`

- [ ] **Step 4: Verify B2B API data**

Read `spa/src/api/b2b/customer.ts` and `spa/src/api/b2b/supplier.ts`. Add dashboard summary endpoint calls if missing:
```typescript
// customer.ts
dashboard: () => client.get<CustomerDashboardSummary>('/b2b/customer/dashboard').then(r => r.data),

// supplier.ts
dashboard: () => client.get<SupplierDashboardSummary>('/b2b/supplier/dashboard').then(r => r.data),
```

- [ ] **Step 5: Commit**
```bash
git add spa/src/pages/portal/
git commit -m "feat: B2B portal dashboards — real KPI cards and quick navigation"
```

---

## TASK 14: NEW FEATURE — Inventory Reorder Point Alerts

**Why:** Inventory module has stock levels but no automated reorder alerts visible in UI. Adding reorder point tracking + alert badges transforms inventory from a passive ledger into an active planning tool. Backend has `alerts.ts` API.

**Files:**
- Modify: `api/app/Modules/Inventory/Models/Item.php` — verify `reorder_point` field
- Modify: `spa/src/pages/inventory/stock-levels/index.tsx` — add reorder alert column
- Modify: `spa/src/pages/inventory/items/detail.tsx` — add reorder point setting + alert status
- Modify: `spa/src/pages/alerts/index.tsx` — ensure inventory low-stock alerts show

- [ ] **Step 1: Verify reorder_point in schema**

```bash
grep -n "reorder_point" /home/kwat0g/Desktop/kwatog/api/database/migrations/0*inventory*
```
If the column exists, proceed. If not, create a small migration:
```php
// api/database/migrations/0170_add_reorder_point_to_items.php
Schema::table('items', function (Blueprint $table) {
    $table->decimal('reorder_point', 15, 2)->nullable()->after('reorder_quantity');
    $table->decimal('reorder_quantity', 15, 2)->nullable()->after('reorder_point');
});
```

- [ ] **Step 2: Add alert column to stock levels page**

Read `spa/src/pages/inventory/stock-levels/index.tsx`. Add column:
```typescript
{
  key: 'reorder_status',
  header: 'Reorder',
  cell: (r) => {
    if (!r.reorder_point) return null;
    if (r.quantity_on_hand <= 0) return <Chip variant="danger">Out of Stock</Chip>;
    if (r.quantity_on_hand <= r.reorder_point) return <Chip variant="warning">Reorder Now</Chip>;
    return <Chip variant="success">OK</Chip>;
  },
}
```
Add a filter: "Show only items below reorder point".

- [ ] **Step 3: Add reorder point edit to item detail**

In `spa/src/pages/inventory/items/detail.tsx`, add an editable inline field for Reorder Point and Reorder Quantity. Simple `<InlineEdit>` or a small form panel.

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/inventory/ api/database/migrations/
git commit -m "feat: inventory reorder point alerts — stock level column + item edit"
```

---

## TASK 15: NEW FEATURE — Payroll Anomaly Dashboard

**Why:** `PayrollAnomalyService` and `PayrollAnomalyFlagResource` exist in backend but the only frontend is a data file at `spa/src/api/payroll/anomalies.ts`. There's no page showing anomaly flags before payroll is finalized. This is a high-value HR control.

**Files:**
- Modify: `spa/src/pages/payroll/pipeline.tsx` — add anomaly step/section (read current content first)
- Create: (if needed) anomaly review component

- [ ] **Step 1: Read payroll pipeline page**

```bash
cat spa/src/pages/payroll/pipeline.tsx
```

- [ ] **Step 2: Read anomaly API**

```bash
cat spa/src/api/payroll/anomalies.ts
```

- [ ] **Step 3: Add anomaly review step**

In `spa/src/pages/payroll/pipeline.tsx`, add an "Anomaly Review" step in the pipeline flow. Before payroll can be finalized, show:

Anomaly table columns:
- Employee (link to employee detail)
- Anomaly Type chip (e.g. "Missing DTR", "OT > 4hrs", "Negative Pay")
- Expected vs Actual
- Action: Override (with reason) or Accept

```typescript
const anomalies = useQuery({
  queryKey: ['payroll', 'anomalies', periodId],
  queryFn: () => payrollAnomaliesApi.list(periodId!),
  enabled: !!periodId,
});

// In pipeline step "3. Review Anomalies":
{anomalies.data?.length > 0 ? (
  <div className="space-y-2">
    <div className="text-sm text-amber-600 font-medium">{anomalies.data.length} anomalies require review before finalizing.</div>
    <DataTable data={anomalies.data} columns={anomalyColumns} />
  </div>
) : (
  <EmptyState message="No anomalies found. Safe to finalize." />
)}
```

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/payroll/pipeline.tsx
git commit -m "feat: payroll pipeline — anomaly review step before finalization"
```

---

## TASK 16: NEW FEATURE — Supplier Performance Scorecard

**Why:** `SupplierPerformanceSnapshot` model and `SupplierPerformanceService` exist but the frontend at `spa/src/pages/purchasing/suppliers/performance.tsx` may be a thin list. A proper scorecard with trends strengthens the Procure-to-Pay chain.

**Files:**
- Modify: `spa/src/pages/purchasing/suppliers/performance.tsx` — add trend charts
- Modify: `spa/src/pages/purchasing/approved-suppliers/index.tsx` — add performance score column

- [ ] **Step 1: Read existing performance page**

```bash
cat spa/src/pages/purchasing/suppliers/performance.tsx
```

- [ ] **Step 2: Enhance with trend charts**

Add to performance page:
1. **Top 5 suppliers by score** — horizontal bar chart
2. **Score trend over time** for selected supplier — line chart (months on X, score on Y)
3. **Breakdown table**: On-time delivery %, Defect rate %, Lead time accuracy %, Price variance

```typescript
// Charts using Recharts
import { BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
```

- [ ] **Step 3: Add score column to approved suppliers**

Read `spa/src/pages/purchasing/approved-suppliers/index.tsx`. Add:
- "Performance Score" column (colored: ≥85 green, ≥70 amber, <70 red)
- Link to performance detail

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/purchasing/
git commit -m "feat: supplier performance scorecard with trend charts"
```

---

## TASK 17: NEW FEATURE — HR Employee Directory with Org Chart

**Why:** `spa/src/pages/hr/directory/index.tsx` exists but is likely a simple list. An org chart view (department tree) is a strong visual for thesis defense and adds real value for HR.

**Files:**
- Modify: `spa/src/pages/hr/directory/index.tsx` — add org chart toggle view

- [ ] **Step 1: Read current directory page**

```bash
cat spa/src/pages/hr/directory/index.tsx
```

- [ ] **Step 2: Add org chart view toggle**

Add a "List / Org Chart" view toggle. For org chart, use a recursive component:

```typescript
// Simple tree rendering — no external library needed
interface OrgNode {
  id: string;
  name: string;
  position: string;
  department: string;
  avatar?: string;
  children: OrgNode[];
}

function OrgNode({ node, depth = 0 }: { node: OrgNode; depth?: number }) {
  return (
    <div className="flex flex-col items-center">
      <div className="border border-border rounded-lg p-3 w-40 text-center text-sm bg-surface">
        <div className="font-medium truncate">{node.name}</div>
        <div className="text-muted text-xs truncate">{node.position}</div>
        <Chip variant="neutral" size="sm" className="mt-1">{node.department}</Chip>
      </div>
      {node.children.length > 0 && (
        <div className="flex gap-6 mt-4 pt-4 border-t border-border">
          {node.children.map(child => <OrgNode key={child.id} node={child} depth={depth+1} />)}
        </div>
      )}
    </div>
  );
}
```

Add API call: `directoryApi.orgChart()` → `/hr/directory/org-chart`.

- [ ] **Step 3: Check org chart backend endpoint**

```bash
grep -n "org.chart\|orgchart\|org_chart" /home/kwat0g/Desktop/kwatog/api/app/Modules/HR/routes.php
```

If missing, add in HR routes:
```php
Route::get('directory/org-chart', [DirectoryController::class, 'orgChart']);
```

And in DirectoryController:
```php
public function orgChart(): JsonResponse {
    // Group employees by manager relationship
    // Return nested structure
}
```

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/hr/directory/ api/app/Modules/HR/
git commit -m "feat: HR directory org chart toggle view"
```

---

## TASK 18: NEW FEATURE — Quality Pareto Dashboard (Defect Analysis)

**Why:** `DefectParetoService` and `CopqService` exist in backend. Quality module has a `quality/dashboard.tsx` but likely lacks a proper defect Pareto chart — the #1 IATF 16949 analysis tool.

**Files:**
- Modify: `spa/src/pages/quality/dashboard.tsx` — add Pareto chart + COPQ summary
- Reuse: `spa/src/components/charts/DowntimeParetoChart.tsx` (from Task 7, same pattern)

- [ ] **Step 1: Read quality dashboard**

```bash
cat spa/src/pages/quality/dashboard.tsx
```

- [ ] **Step 2: Add defect Pareto**

Add to quality dashboard:
1. **Defect Pareto Chart** — top 10 defect types by count, with cumulative % line (reuse DowntimeParetoChart pattern)
2. **COPQ Summary Cards**: Internal failure cost, External failure cost, Appraisal cost, Total COPQ
3. **NCR by Stage** — bar chart: Incoming / In-Process / Outgoing

```typescript
const pareto = useQuery({
  queryKey: ['quality', 'pareto', dateRange],
  queryFn: () => qualityAnalyticsApi.defectPareto(dateRange),
});

const copq = useQuery({
  queryKey: ['quality', 'copq', dateRange],
  queryFn: () => qualityAnalyticsApi.copq(dateRange),
});
```

- [ ] **Step 3: Verify analytics API**

Read `spa/src/api/quality/analytics.ts`. Add missing methods:
```typescript
defectPareto: (params?: { start?: string; end?: string }) =>
  client.get<{ data: ParetoEntry[] }>('/quality/analytics/defect-pareto', { params }).then(r => r.data.data),
copq: (params?: { start?: string; end?: string }) =>
  client.get<{ data: CopqSummary }>('/quality/analytics/copq', { params }).then(r => r.data.data),
```

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/quality/dashboard.tsx spa/src/api/quality/analytics.ts
git commit -m "feat: quality dashboard — defect Pareto chart + COPQ summary cards"
```

---

## TASK 19: NEW FEATURE — SPC Control Charts on Inspection Detail

**Why:** `SpcService` exists in backend. SPC (Statistical Process Control) control charts are a defining feature of IATF 16949 quality systems and a major thesis differentiator. No UI for it yet.

**Files:**
- Modify: `spa/src/pages/quality/inspections/detail.tsx` — add SPC control chart tab

- [ ] **Step 1: Read inspection detail page**

```bash
cat spa/src/pages/quality/inspections/detail.tsx
```

- [ ] **Step 2: Add SPC tab**

Add a "Control Chart" tab to the inspection detail page. When active, load SPC data for the selected inspection spec item and render a control chart:

```typescript
const spc = useQuery({
  queryKey: ['quality', 'spc', inspection?.product_id, specItemId],
  queryFn: () => qualityAnalyticsApi.spcData({ product_id: inspection!.product_id, spec_item_id: specItemId }),
  enabled: !!inspection && !!specItemId,
});

// Control chart = line chart with UCL/LCL/CL horizontal reference lines
import { LineChart, Line, ReferenceLine, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';

<ResponsiveContainer width="100%" height={280}>
  <LineChart data={spc.data?.points}>
    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
    <XAxis dataKey="inspection_date" tick={{ fontSize: 11 }} />
    <YAxis tick={{ fontSize: 11 }} domain={['auto', 'auto']} />
    <Tooltip />
    <ReferenceLine y={spc.data?.ucl} stroke="var(--color-danger)" strokeDasharray="4 4" label="UCL" />
    <ReferenceLine y={spc.data?.cl} stroke="var(--color-info)" label="CL" />
    <ReferenceLine y={spc.data?.lcl} stroke="var(--color-danger)" strokeDasharray="4 4" label="LCL" />
    <Line type="monotone" dataKey="value" stroke="var(--color-primary)" dot={{ r: 3 }} />
  </LineChart>
</ResponsiveContainer>
```

- [ ] **Step 3: Add SPC API method**

In `spa/src/api/quality/analytics.ts`:
```typescript
spcData: (params: { product_id: string; spec_item_id: string; limit?: number }) =>
  client.get<{ data: SpcResult }>('/quality/analytics/spc', { params }).then(r => r.data.data),
```

- [ ] **Step 4: Commit**
```bash
git add spa/src/pages/quality/inspections/detail.tsx spa/src/api/quality/analytics.ts
git commit -m "feat: quality inspection detail — SPC control chart tab"
```

---

## TASK 20: POLISH — Fix Thin Pages, Add Missing Route Guards

**Why:** Several pages discovered during audit are functional but missing edge cases: empty states, error states, or permission guards. This task does a sweep.

**Files:** Multiple small modifications

- [ ] **Step 1: Sweep for missing permission guards**

```bash
grep -rL "usePermission\|PermissionGuard\|can(" spa/src/pages/ --include="*.tsx" | grep -v gitkeep
```

For each page that does write operations (create/edit forms, approve buttons) without a permission check, add:
```typescript
const { can } = usePermission();
// Before submit button:
disabled={!can('module.resource.action')}
```

- [ ] **Step 2: Sweep for missing error states**

```bash
grep -rL "isError\|error" spa/src/pages/ --include="*.tsx" | head -20
```

For pages with `useQuery` but no `isError` handling, add:
```typescript
if (isError) return <ErrorState onRetry={refetch} />;
```

- [ ] **Step 3: Add missing sidebar nav items**

Check Sidebar.tsx or nav config. Ensure these routes have nav entries:
- `/crm/customers` (new from Task 1)
- `/accounting/collections` (new from Task 12)
- `/budgeting/transfers` (new from Task 8)

- [ ] **Step 4: Fix forecasting pages**

Read `spa/src/pages/forecasting/demand.tsx` (388 lines) and `spa/src/pages/forecasting/stock-out.tsx` (174 lines). If they lack charts, add demand trend line chart to demand.tsx.

- [ ] **Step 5: Final commit**
```bash
git add spa/src/
git commit -m "polish: permission guards, error states, sidebar nav — full sweep"
```

---

## EXECUTION ORDER (by impact)

| Priority | Task | Impact | Time |
|----------|------|--------|------|
| 🔴 P1 | Task 1 — CRM Customers | Blocks Chain 1 demo | 4-6 hrs |
| 🔴 P1 | Task 5 — Gantt Schedule | Thesis showcase | 3-4 hrs |
| 🔴 P1 | Task 18 — Quality Pareto | IATF differentiator | 2-3 hrs |
| 🟡 P2 | Task 6 — MRP Shortage View | Core MRP value | 2-3 hrs |
| 🟡 P2 | Task 7 — Downtime Charts | OEE KPI | 2-3 hrs |
| 🟡 P2 | Task 8 — Budget Variance Chart | Finance module | 2 hrs |
| 🟡 P2 | Task 10 — CoC Print | IATF document | 1-2 hrs |
| 🟢 P3 | Task 2 — Price Agreement Create | CRM completeness | 1-2 hrs |
| 🟢 P3 | Task 3 — Shipment Create/Detail | Supply chain | 2-3 hrs |
| 🟢 P3 | Task 9 — Assets Complete | Asset module | 2 hrs |
| 🟢 P3 | Task 11 — RMA Approval | Chain 1 | 1-2 hrs |
| 🟢 P3 | Task 12 — Collections | AR completeness | 2 hrs |
| 🟢 P3 | Task 13 — Portal Dashboards | Demo-worthy | 2-3 hrs |
| 🔵 P4 | Task 15 — Payroll Anomaly | HR control | 2 hrs |
| 🔵 P4 | Task 16 — Supplier Scorecard | Procurement | 2 hrs |
| 🔵 P4 | Task 17 — Org Chart | Visual wow | 2-3 hrs |
| 🔵 P4 | Task 19 — SPC Charts | IATF differentiator | 2-3 hrs |
| 🔵 P4 | Task 4 — Delivery Create | Supply chain | 1-2 hrs |
| ⚪ P5 | Task 14 — Reorder Alerts | Inventory ops | 1-2 hrs |
| ⚪ P5 | Task 20 — Polish Sweep | Quality | 2-3 hrs |

**Total estimate: 40-55 hours of focused implementation.**

---

## FINAL CHECKLIST (run before each task is marked complete)

- [ ] No raw integer IDs in API responses or URLs — `hash_id` only
- [ ] All write operations in `DB::transaction()`
- [ ] Form has: Zod schema, disabled submit while pending, server error mapping, cancel button
- [ ] List page handles: loading skeleton, error+retry, empty state, data table
- [ ] Numbers use `font-mono tabular-nums`
- [ ] Status chips use `<Chip>` with semantic variant
- [ ] Mutations have `toast.success` and `toast.error` + `queryClient.invalidateQueries`
- [ ] New routes wrapped in `AuthGuard` + `ModuleGuard` + `PermissionGuard`
- [ ] Pages are `React.lazy()` imported
- [ ] Git commit after every task
