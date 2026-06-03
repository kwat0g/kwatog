# Kill Hub Pages — Inline Sub-Feature Navigation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove all hub pages. Sub-features (Categories, Overtime, Holidays, Shifts, Gov Tables, etc.) become accessible via action buttons on their parent page's PageHeader — never via sidebar or hub landing pages.

**Architecture:** Sidebar points directly to parent pages. Each parent page gets secondary action buttons to its sub-features in the PageHeader `actions` slot. Hub pages are deleted. Routes remain (direct URL access preserved). `backTo` links on sub-pages point to their parent page instead of former hub.

**Tech Stack:** React 18, TypeScript, react-router-dom, existing PageHeader/Button components

---

## Design Pattern

Every parent page already uses `<PageHeader actions={...}>`. Sub-features become `<Button variant="secondary">` in that actions slot:

```tsx
<PageHeader
  title="Attendance"
  actions={
    <>
      <Button variant="secondary" size="sm" onClick={() => navigate('/hr/attendance/overtime')}>Overtime</Button>
      <Button variant="secondary" size="sm" onClick={() => navigate('/hr/attendance/shifts')}>Shifts</Button>
      <Button variant="secondary" size="sm" onClick={() => navigate('/hr/attendance/holidays')}>Holidays</Button>
      <Button variant="primary" size="sm" onClick={() => navigate('/hr/attendance/import')}>Import DTR</Button>
    </>
  }
/>
```

Sub-pages use `backTo` pointing to parent:
```tsx
<PageHeader title="Overtime" backTo="/hr/attendance" backLabel="Attendance" />
```

---

## Sidebar Changes

Replace hub links with direct parent page links:

| Current | New |
|---------|-----|
| `/inventory/hub` → "Inventory Hub" | `/inventory/items` → "Items" |
| `/accounting/hub` → "Accounting Hub" | `/accounting/journal-entries` → "Journal Entries" |
| `/hr/attendance/hub` → "Attendance Hub" | `/hr/attendance` → "Attendance" |
| `/payroll/hub` → "Payroll Hub" | `/payroll/periods` → "Payroll" |

---

## Sub-Feature Mapping (which buttons go on which parent page)

| Parent Page | Sub-Feature Buttons |
|-------------|-------------------|
| `/hr/attendance` (Attendance DTR) | Overtime, Shifts, Holidays, Import DTR, Bulk Assign |
| `/payroll/periods` (Payroll Periods) | Adjustments, Pipeline, Gov Tables |
| `/inventory/items` (Items list) | Categories, Warehouses, Stock Levels, Movements, Stock Count, Picking, Transfers, Warehouse Map |
| `/accounting/journal-entries` (Journal Entries) | COA, Vendors, Trial Balance, Income Statement, Balance Sheet, Budgets |

---

### Task 1: Update Sidebar — remove hub references

**Files:**
- Modify: `spa/src/components/layout/Sidebar.tsx`

- [ ] **Step 1: Replace hub links with parent page links**

Change these 4 entries in the SECTIONS array:

```typescript
// Warehouse section — change:
{ to: '/inventory/hub', label: 'Inventory Hub', ... }
// to:
{ to: '/inventory/items', label: 'Items', icon: Boxes, feature: 'inventory', permission: 'inventory.view', badgeKey: 'low_stock' },

// Finance section — change:
{ to: '/accounting/hub', label: 'Accounting Hub', ... }
// to:
{ to: '/accounting/journal-entries', label: 'Journal Entries', icon: BookOpen, feature: 'accounting', permission: 'accounting.journal.view' },

// HR section — change:
{ to: '/hr/attendance/hub', label: 'Attendance Hub', ... }
// to:
{ to: '/hr/attendance', label: 'Attendance', icon: Clock4, feature: 'attendance', permission: 'attendance.view', badgeKey: 'leaves' },

// HR section — change:
{ to: '/payroll/hub', label: 'Payroll Hub', ... }
// to:
{ to: '/payroll/periods', label: 'Payroll', icon: Wallet, feature: 'payroll', permission: 'payroll.view', badgeKey: 'payroll' },
```

Also update the JSDoc comment at top to remove hub references.

- [ ] **Step 2: Verify typecheck**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit
```

- [ ] **Step 3: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add spa/src/components/layout/Sidebar.tsx
git commit -m "refactor: sidebar points directly to parent pages, remove hub links"
```

---

### Task 2: Add sub-feature buttons to Attendance page

**Files:**
- Modify: `spa/src/pages/attendance/index.tsx`

The Attendance page already has Overtime + Import DTR buttons. Add: Shifts, Holidays.

- [ ] **Step 1: Add Shifts and Holidays buttons to PageHeader actions**

In `spa/src/pages/attendance/index.tsx`, find the `<PageHeader ... actions={...}>` block. Add new buttons before the existing ones:

```typescript
// Add to imports:
import { Upload, Calendar, Clock, Sun } from 'lucide-react';

// In actions prop, add these buttons (before existing Overtime button):
<Button variant="secondary" size="sm" icon={<Clock size={14} />} onClick={() => navigate('/hr/attendance/shifts')}>
  Shifts
</Button>
<Button variant="secondary" size="sm" icon={<Sun size={14} />} onClick={() => navigate('/hr/attendance/holidays')}>
  Holidays
</Button>
```

- [ ] **Step 2: Remove `backTo="/hr/attendance/hub"` — this IS the parent page now**

If there's a `backTo` pointing to the hub, remove it or point it to `/hr`.

- [ ] **Step 3: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add spa/src/pages/attendance/index.tsx
git commit -m "feat: add Shifts/Holidays buttons to Attendance page header"
```

---

### Task 3: Add sub-feature buttons to Payroll Periods page

**Files:**
- Modify: `spa/src/pages/payroll/periods/index.tsx`

- [ ] **Step 1: Add Adjustments, Pipeline, Gov Tables buttons**

In the PageHeader `actions`, add before the existing "New Period" button:

```typescript
<Button variant="secondary" size="sm" onClick={() => navigate('/payroll/adjustments')}>Adjustments</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/payroll/pipeline')}>Pipeline</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/admin/gov-tables')}>Gov Tables</Button>
```

- [ ] **Step 2: Change `backTo="/payroll/hub"` to `backTo="/payroll/periods"` or remove it**

Since this IS the payroll parent page now, remove `backTo` or point to a sensible parent like `/dashboard`.

- [ ] **Step 3: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add spa/src/pages/payroll/periods/index.tsx
git commit -m "feat: add Adjustments/Pipeline/Gov Tables buttons to Payroll page"
```

---

### Task 4: Add sub-feature buttons to Items list page

**Files:**
- Modify: `spa/src/pages/inventory/items/index.tsx`

- [ ] **Step 1: Add inventory sub-feature buttons**

Add to PageHeader actions (secondary buttons):

```typescript
<Button variant="secondary" size="sm" onClick={() => navigate('/inventory/categories')}>Categories</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/inventory/warehouse')}>Warehouses</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/inventory/stock-levels')}>Stock Levels</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/inventory/movements')}>Movements</Button>
```

Note: Don't add ALL sub-pages — only the most relevant 4-5. Stock Count, Picking, Transfers, Warehouse Map are accessible from their related pages (GRN page links to warehouse map, etc.) or via Cmd+K search.

- [ ] **Step 2: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add spa/src/pages/inventory/items/index.tsx
git commit -m "feat: add Categories/Warehouses/Stock Levels/Movements buttons to Items page"
```

---

### Task 5: Add sub-feature buttons to Journal Entries page

**Files:**
- Modify: `spa/src/pages/accounting/journal-entries/index.tsx`

- [ ] **Step 1: Add accounting sub-feature buttons**

Add to PageHeader actions:

```typescript
<Button variant="secondary" size="sm" onClick={() => navigate('/accounting/coa')}>COA</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/accounting/vendors')}>Vendors</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/accounting/trial-balance')}>Trial Balance</Button>
<Button variant="secondary" size="sm" onClick={() => navigate('/budgeting')}>Budgets</Button>
```

- [ ] **Step 2: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add spa/src/pages/accounting/journal-entries/index.tsx
git commit -m "feat: add COA/Vendors/Trial Balance/Budgets buttons to Journal Entries page"
```

---

### Task 6: Fix all `backTo` references pointing to deleted hubs

**Files:**
- Multiple files that have `backTo="/hr/attendance/hub"`, `backTo="/payroll/hub"`, etc.

- [ ] **Step 1: Find all backTo hub references**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && grep -rn 'backTo.*hub' src/pages/
```

- [ ] **Step 2: Replace each one**

| Old | New |
|-----|-----|
| `backTo="/hr/attendance/hub"` | `backTo="/hr/attendance"` |
| `backTo="/payroll/hub"` | `backTo="/payroll/periods"` |
| `backTo="/inventory/hub"` | `backTo="/inventory/items"` |
| `backTo="/accounting/hub"` | `backTo="/accounting/journal-entries"` |

- [ ] **Step 3: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add -A
git commit -m "fix: update backTo links from deleted hubs to parent pages"
```

---

### Task 7: Delete hub pages and remove hub routes

**Files:**
- Delete: `spa/src/pages/attendance/hub.tsx`
- Delete: `spa/src/pages/payroll/hub.tsx`
- Delete: `spa/src/pages/inventory/hub.tsx`
- Delete: `spa/src/pages/accounting/hub.tsx`
- Modify: `spa/src/routes/dashboardRoutes.tsx` (remove payroll hub, attendance hub routes)
- Modify: `spa/src/routes/inventoryRoutes.tsx` (remove inventory hub route)
- Modify: `spa/src/routes/accountingRoutes.tsx` (remove accounting hub route)

- [ ] **Step 1: Delete hub page files**

```bash
cd /home/kwat0g/Desktop/kwatog/spa
rm src/pages/attendance/hub.tsx
rm src/pages/payroll/hub.tsx
rm src/pages/inventory/hub.tsx
rm src/pages/accounting/hub.tsx
```

- [ ] **Step 2: Remove hub routes from route files**

In each route file, remove:
- The lazy import for the hub page
- The `<Route path="xxx/hub" ...>` element
- Change any `<Navigate to="xxx/hub" ...>` to point to the parent page instead

For `dashboardRoutes.tsx` — remove `/payroll/hub` and `/hr/attendance/hub` routes.
For `inventoryRoutes.tsx` — remove `/inventory/hub` route, change `/inventory` redirect to `/inventory/items`.
For `accountingRoutes.tsx` — remove `/accounting/hub` route, change `/accounting` redirect to `/accounting/journal-entries`.

- [ ] **Step 3: Verify typecheck**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit
```

- [ ] **Step 4: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add -A
git commit -m "refactor: delete all hub pages, update route redirects to parent pages"
```

---

### Task 8: Remove unused hub components

**Files:**
- Delete: `spa/src/components/hub/HubPage.tsx`
- Delete: `spa/src/components/hub/HubCard.tsx`
- Delete: `spa/src/components/hub/NavTile.tsx`
- Delete: `spa/src/components/hub/index.ts`

- [ ] **Step 1: Delete hub component directory**

```bash
rm -rf /home/kwat0g/Desktop/kwatog/spa/src/components/hub/
```

- [ ] **Step 2: Verify no remaining imports**

```bash
grep -rn '@/components/hub' /home/kwat0g/Desktop/kwatog/spa/src/
```

Should return nothing. If anything references them, remove the import.

- [ ] **Step 3: Verify typecheck**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit
```

- [ ] **Step 4: Commit**

```bash
cd /home/kwat0g/Desktop/kwatog && git add -A
git commit -m "chore: remove unused HubPage/HubCard/NavTile components"
```

---

### Task 9: Final verification

- [ ] **Step 1: Full typecheck**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && npx tsc --noEmit
```

- [ ] **Step 2: Verify no orphan references**

```bash
cd /home/kwat0g/Desktop/kwatog/spa && grep -rn 'hub' src/ | grep -v node_modules | grep -v '.git'
```

Should only show legitimate uses (GitHub links, etc.), not route/page references.

- [ ] **Step 3: Verify sidebar has no hub text**

```bash
grep -i 'hub' /home/kwat0g/Desktop/kwatog/spa/src/components/layout/Sidebar.tsx
```

Should return nothing.

---

## Summary of Final State

| Sidebar Item | Points To | Sub-features accessible via |
|-------------|-----------|---------------------------|
| Attendance | `/hr/attendance` | Buttons: Overtime, Shifts, Holidays, Import DTR |
| Payroll | `/payroll/periods` | Buttons: Adjustments, Pipeline, Gov Tables |
| Items | `/inventory/items` | Buttons: Categories, Warehouses, Stock Levels, Movements |
| Journal Entries | `/accounting/journal-entries` | Buttons: COA, Vendors, Trial Balance, Budgets |

All hub pages deleted. All sub-pages still accessible via URL and via buttons on parent.
