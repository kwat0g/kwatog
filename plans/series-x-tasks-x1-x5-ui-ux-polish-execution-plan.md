# Series X (Tasks X1–X5) — UI/UX Polish Execution Plan

> Source: [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:1042) Series X.
> Conventions: [`CLAUDE.md`](../CLAUDE.md:1), [`docs/PATTERNS.md`](../docs/PATTERNS.md:1), [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:1).
> Scope: **SPA-only.** No backend changes (no migrations, controllers, resources, routes). One optional npm dependency (`react-hotkeys-hook`).

---

## 1. Scope & Non-Goals

| Task | Theme | Net new code | Edits to existing |
|---|---|---|---|
| X1 | Keyboard shortcuts | `useKeyboardShortcuts`, `KeyboardShortcutHelp` modal, registry | [`AppLayout`](../spa/src/layouts/AppLayout.tsx:1), [`Topbar`](../spa/src/components/layout/Topbar.tsx:1) |
| X2 | Smart form improvements | `useUnsavedChangesGuard`, `useFormDraftAutosave`, `useInlineValidation`, `CurrencyInput`, `CharacterCounter` on [`Textarea`](../spa/src/components/ui/Textarea.tsx:1) | All form pages (rolled out gradually; pattern documented) |
| X3 | Better empty states | Expanded icon registry on [`EmptyState`](../spa/src/components/ui/EmptyState.tsx:1) | All 30+ list pages — replace generic copy with context-specific copy |
| X4 | Data table enhancements | Column pinning, row expand, column resize, column visibility, sticky header, context menu, inline edit — added to [`DataTable`](../spa/src/components/ui/DataTable.tsx:1); per-user prefs store | List pages opt in via new props |
| X5 | Loading & transition polish | `RouteTransition` wrapper, "Refreshing…" badge in `PageHeader`, optimistic-update helper | [`App.tsx`](../spa/src/App.tsx:1) Suspense layout, all detail pages with TanStack Query |

**Non-goals (explicitly):**

- No new modules, no new permissions, no DB schema changes.
- No Bearer-token paths, no `localStorage` for **auth** (autosave drafts to `localStorage` is allowed — no PII or auth secrets).
- No introduction of `dnd-kit` or react-grid-layout (see [`CLAUDE.md`](../CLAUDE.md:73) — customizable dashboards are explicitly cut). Drag/resize in X4 means **column resize**, not full layout DnD.

**Pattern alignment** (per [`docs/PATTERNS.md`](../docs/PATTERNS.md:1522)): every page must keep its 5 mandatory states (loading / error / empty / data / stale); X3 and X5 strengthen 2 of them, X4 must not break any.

---

## 2. Pre-flight checks (mandatory, run before starting)

From [`.roo/skills/kwatog/code-quality-gate.md`](../.roo/skills/kwatog/code-quality-gate.md:1):

```bash
cd spa && npm run lint && npm run typecheck && npm run test -- --run
```

Capture baseline; gate must still pass at the end of each task.

---

## 3. Task X1 — Keyboard Shortcuts System

### 3.1 Dependency
Add to [`spa/package.json`](../spa/package.json:1):
```
"react-hotkeys-hook": "^4.5.0"
```

### 3.2 Files to create

| Path | Purpose |
|---|---|
| `spa/src/lib/shortcuts.ts` | Central registry: array of `{ keys, label, group, scope, action }` describing every shortcut. Single source of truth so the help modal and the hook stay in sync. |
| `spa/src/hooks/useKeyboardShortcuts.ts` | Mounts global navigation + action shortcuts via `useHotkeys`. Reads scope from a context (`'global' \| 'table' \| 'form' \| 'modal'`). Skips when target is an `<input>`/`<textarea>` (default `react-hotkeys-hook` behavior). |
| `spa/src/stores/shortcutScopeStore.ts` | Tiny Zustand store: `currentScope`, `setScope`, `pushModal`, `popModal`. Used by `Modal` to push `'modal'` scope so `Esc` closes the topmost modal first. |
| `spa/src/components/ui/KeyboardShortcutHelp.tsx` | Two-column modal grouped by Navigation / Actions / Table. Triggered by `?` key. Renders straight from the registry. Uses [`Modal`](../spa/src/components/ui/Modal.tsx:1). |
| `spa/src/lib/shortcuts.test.ts` | Vitest unit test: registry has no duplicate `keys` per scope, all entries have `label` and `group`. |

### 3.3 Files to edit

- [`spa/src/layouts/AppLayout.tsx`](../spa/src/layouts/AppLayout.tsx:1): mount `useKeyboardShortcuts()` once at the top of authenticated tree; render `<KeyboardShortcutHelp />` portal-style.
- [`spa/src/components/layout/Topbar.tsx`](../spa/src/components/layout/Topbar.tsx:1): add a small `?` icon button (28px, ghost) near the avatar that opens the help modal, so the shortcut is also discoverable by mouse.
- [`spa/src/components/ui/Modal.tsx`](../spa/src/components/ui/Modal.tsx:1): on mount push `'modal'` scope, on unmount pop. Existing `Esc`-to-close behavior stays — but only the topmost modal consumes it.
- [`spa/src/components/ui/CommandPalette.tsx`](../spa/src/components/ui/CommandPalette.tsx:1): registry should declare `mod+k` so the help modal shows it; the actual hook to open the palette stays where it lives today (no behavior change).

### 3.4 Registry contents (initial)

| Group | Keys | Action | Scope |
|---|---|---|---|
| Navigation | `g h` | `/hr/employees` | global |
| Navigation | `g p` | `/payroll/periods` | global |
| Navigation | `g a` | `/accounting` | global |
| Navigation | `g i` | `/inventory/items` | global |
| Navigation | `g s` | `/crm/sales-orders` | global |
| Navigation | `g m` | `/mrp/plans` | global |
| Navigation | `g d` | `/dashboard` | global |
| Actions | `mod+k` | open command palette | global |
| Actions | `mod+s` | submit current form | form |
| Actions | `mod+shift+n` | open create modal/route on current list page | global |
| Actions | `mod+e` | trigger export on current list | global |
| Actions | `mod+p` | trigger print on current detail | global |
| Actions | `Escape` | close topmost modal/panel | modal |
| Help | `?` | toggle shortcut help | global |
| Table | `j` / `k` | next / prev row | table |
| Table | `Enter` | open selected row | table |
| Table | `Space` | toggle row selection | table |
| Table | `mod+a` | select all rows | table |

`mod+s`, `mod+shift+n`, `mod+e`, `mod+p` resolve through a context dispatcher. List pages register handlers via a new helper `usePageActions({ onCreate, onExport, onPrint })`. Forms register `onSubmit` via `useFormSubmitShortcut(handleSubmit)`. Pages without a handler simply do nothing.

### 3.5 Acceptance criteria

- [ ] Pressing `?` anywhere outside an input opens the help modal; pressing `?` again or `Esc` closes it.
- [ ] `g h` navigates to `/hr/employees`; `g d` to `/dashboard`. Two-key sequences time out after ~1 s.
- [ ] Shortcuts do **not** fire while focus is in a text input/textarea/contenteditable.
- [ ] `Esc` closes the topmost open modal only — not all of them, not the page.
- [ ] Vitest: `shortcuts.test.ts` asserts no duplicate keys per scope.
- [ ] `npm run lint && npm run typecheck && npm run test -- --run` all pass.

---

## 4. Task X2 — Smart Form Improvements

The existing [Form Pattern](../docs/PATTERNS.md:1084) already mandates RHF + Zod, server-side error mapping, disabled-while-pending, success/error toast, and a cancel button. X2 layers six enhancements on top.

### 4.1 Files to create

| Path | Purpose |
|---|---|
| `spa/src/hooks/useUnsavedChangesGuard.ts` | `useUnsavedChangesGuard(isDirty)` — uses `beforeunload` for tab close + `useBlocker` from `react-router-dom` for in-app navigation. Renders a small confirm dialog (uses [`ConfirmDialog`](../spa/src/components/ui/ConfirmDialog.tsx:1)) on attempted nav. |
| `spa/src/hooks/useFormDraftAutosave.ts` | Debounced (30 s) write of `getValues()` to `localStorage` under key `draft:${formKey}`. Excludes any field whose name matches a configurable blocklist (`/sss\|tin\|bank\|password\|salary/i` by default — government IDs, banking, salaries, passwords are **never** persisted to client storage). Returns `{ hasDraft, draftAge, restore, discard }`. |
| `spa/src/components/ui/DraftRestoreBanner.tsx` | Yellow info banner ("You have unsaved changes from 5 minutes ago. Restore / Discard"). Rendered above the form when `hasDraft`. |
| `spa/src/lib/numberInput.ts` (extend existing) | Add `formatCurrencyDisplay(value)` and `parseCurrencyInput(input)` helpers — display "486,500.00" while storing raw `"486500"`. |
| `spa/src/components/ui/CurrencyInput.tsx` | Wraps [`Input`](../spa/src/components/ui/Input.tsx:1), formats display value, exposes raw decimal string via `onChange`. Used wherever a money field exists. |
| `spa/src/hooks/useInlineValidation.ts` | Wrapper around RHF that triggers `trigger(field)` on blur and exposes `isValid` per field for green-check rendering. |

### 4.2 Files to edit

- [`spa/src/components/ui/Textarea.tsx`](../spa/src/components/ui/Textarea.tsx:1): when `maxLength` is set, render a small `text-2xs text-muted` counter under the field: `128 / 500`. Counter goes amber at 90 %, danger red at 100 %.
- [`spa/src/components/ui/Input.tsx`](../spa/src/components/ui/Input.tsx:1): add optional `validState?: 'idle' \| 'valid' \| 'invalid'` to render a 12 px Lucide check (success-fg) or X (danger-fg) inside the right padding when configured. Default `idle` keeps existing visuals.
- One **reference rollout** to demonstrate the pattern in practice: [`spa/src/pages/hr/employees/form.tsx`](../spa/src/pages/hr/employees/form.tsx:1) — wires `useUnsavedChangesGuard`, `useFormDraftAutosave({ formKey: 'hr.employees.create', blocklist: ['sss_no', 'tin', 'bank_account_no', 'basic_monthly_salary', 'daily_rate'] })`, replaces salary `Input` with `CurrencyInput`, and adds the inline-validation visual on `email`.

The remaining ~25 form pages **are not** rolled out in this PR (would explode review surface). The plan documents the per-form rollout pattern in Section 4.4 so subsequent PRs can sweep them.

### 4.3 Smart-default policy (no code change, doc only)

A new section "Smart Defaults" in [`docs/PATTERNS.md`](../docs/PATTERNS.md:1) right after the Create Form Pattern, listing the four documented prefill flows:

| Source page | Target form | Prefilled fields |
|---|---|---|
| Sales Order detail | Work Order create | `product_id`, `customer_id`, `sales_order_id`, `quantity` |
| GRN detail | Bill create | `vendor_id`, line items, `subtotal`, `vat`, `total` |
| Employee detail | Leave create | `employee_id`, `department_id` |
| Purchase Request detail | Purchase Order create | `vendor_id` (suggested), line items |

Mechanism: pass `state` via `navigate(target, { state: { prefill: {...} } })`; the form reads `useLocation().state?.prefill` and merges into `defaultValues`. No new infrastructure needed.

### 4.4 Per-form rollout checklist (to be executed in a follow-up sweep)

For each form page:

1. Add `useUnsavedChangesGuard(formState.isDirty && !mutation.isSuccess)`.
2. Add `useFormDraftAutosave({ formKey, blocklist })` — pick `formKey` as `<module>.<resource>.<create|edit>`. Always blocklist sensitive fields.
3. Replace any peso/rate `<Input>` with `<CurrencyInput>`.
4. On `<Textarea>` with `maxLength`, the counter is automatic.
5. For `email`, `mobile_number`, `tin`, `sss_no`-shape fields, set `validState` from RHF errors / dirty / touched.

### 4.5 Acceptance criteria

- [ ] Editing the employee form, then closing the tab → browser shows the native "leave site?" prompt; clicking a sidebar link → in-app confirm dialog.
- [ ] Editing the form, refreshing → restore banner appears, restoring fills only non-blocklisted fields. Government IDs / salaries are blank after restore (not silently persisted).
- [ ] Typing `486500` into Monthly Salary displays `486,500.00`; the value submitted to the API is `"486500.00"` and matches existing FormRequest validation.
- [ ] Textarea with `maxLength={500}` shows `n / 500`, colors change at 90 % / 100 %.
- [ ] Email field shows a green check after a valid email loses focus; red X for invalid.
- [ ] Existing form behaviors preserved: submit-disabled-while-pending, server-error mapping, cancel button, success/error toast, `queryClient.invalidateQueries`.
- [ ] Quality gate passes.

---

## 5. Task X3 — Better Empty States (Context-Aware)

### 5.1 Files to edit

- [`spa/src/components/ui/EmptyState.tsx`](../spa/src/components/ui/EmptyState.tsx:1): expand `ICONS` to include `users`, `factory`, `package`, `wrench`, `truck`, `receipt`, `dollar-sign`, `clipboard`, `bar-chart`, `box`, `search-x`, `calendar`, `shield`. Today only six icons are mapped. Keep the API backwards compatible.
- Add an optional `searchTerm?: string` prop on `EmptyState` that, when present, replaces the title with `No <items> match "<term>"` and replaces description with "Try adjusting your search terms or clearing the filters." plus a default "Clear all filters" action button.

### 5.2 Files to create

| Path | Purpose |
|---|---|
| `spa/src/lib/emptyStateCopy.ts` | A typed map of every list page route prefix → `{ icon, title, description, primaryActionLabel, primaryActionRoute }` for both **no-data-yet** and **search-empty** modes. Single source of truth for empty-state copy. Tested by snapshot test. |
| `spa/src/lib/emptyStateCopy.test.ts` | Snapshot test — fails if copy is regressed accidentally. |

### 5.3 Per-page sweep

Every list page consumes `emptyStateCopy` via a small helper:

```tsx
const copy = emptyStateCopy['/hr/employees'];
{data && data.data.length === 0 && (
  filters.search
    ? <EmptyState icon="search-x" searchTerm={filters.search} title="" description=""
        action={<Button variant="secondary" onClick={clearFilters}>Clear all filters</Button>} />
    : <EmptyState
        icon={copy.icon}
        title={copy.title}
        description={copy.description}
        action={can(copy.permission) ? <Button variant="primary" onClick={() => navigate(copy.actionRoute)}>{copy.actionLabel}</Button> : undefined}
      />
)}
```

Pages to update (full list — minimum the audit-mandated 30):

```
/hr/employees                /hr/departments                /hr/positions
/hr/attendance               /hr/attendance/holidays        /hr/attendance/overtime
/hr/attendance/shifts        /leaves                        /loans
/payroll/periods             /payroll/adjustments
/inventory/items             /inventory/categories          /inventory/grn
/inventory/material-issues   /inventory/movements
/purchasing/purchase-requests  /purchasing/purchase-orders  /purchasing/approved-suppliers
/accounting/vendors          /accounting/customers          /accounting/bills
/accounting/invoices         /accounting/journal-entries
/crm/products                /crm/sales-orders              /crm/complaints
/crm/price-agreements        /mrp/plans                     /mrp/boms
/mrp/machines                /mrp/molds
/quality/inspection-specs    /quality/inspections           /quality/ncrs
/maintenance/work-orders     /supply-chain/deliveries       /supply-chain/shipments
/admin/users                 /admin/audit-logs              /alerts
```

Each entry written by hand (not generated) — copy must be specific and helpful, e.g. for `/production/work-orders`:

> "No active work orders — Work orders are created automatically when a Sales Order is confirmed and MRP planning is complete. **[Create Sales Order]**"

### 5.4 Acceptance criteria

- [ ] Every list page in the table above renders a context-specific empty state when truly empty, and a search-aware empty state when filters are active.
- [ ] Permission gating preserved: the primary action button on the empty state only renders when the user can perform it (uses [`usePermission`](../spa/src/hooks/usePermission.ts:1)).
- [ ] Snapshot test prevents regressing copy accidentally.
- [ ] Quality gate passes.

---

## 6. Task X4 — Data Table Enhancements

### 6.1 What already exists in [`DataTable`](../spa/src/components/ui/DataTable.tsx:1)

- Density toggle (compact/default/spacious) ✅
- Row selection + bulk actions ✅
- Sort, pagination ✅
- `defaultHidden` field on `Column<T>` (placeholder, not wired up)

### 6.2 What X4 adds

| # | Feature | Impl strategy |
|---|---|---|
| 1 | **Column pinning** | Add `pinned?: 'left'` to `Column<T>`. Pinned columns rendered first with `position: sticky; left: <cumulative width>; z-index: 2`, plus a 0.5 px right border at the boundary. No drag-to-pin in v1 — declarative only. |
| 2 | **Row expand** | New optional prop `renderExpanded?: (row: T) => ReactNode`. When present, prepend a 28 px "chevron" cell. Local `Set<string>` of expanded ids. Expanded panel renders as a full-width `<tr>` of `colSpan = columns + 1`, padding 12 px, `bg-subtle`. |
| 3 | **Column resize** | Header `<th>` gets a 4 px right edge handle (`cursor: col-resize`). On `mousedown`, start tracking width; commit on `mouseup`. Per-column widths stored in the prefs store (Section 6.4). Min 60 px. |
| 4 | **Column visibility** | Re-use [`ColumnSelectorModal`](../spa/src/components/exports/ColumnSelectorModal.tsx:1) — already exists for E2 exports. Toolbar gets a `[Customize columns]` ghost button next to density toggle. Hidden columns persisted in prefs store. |
| 5 | **Sticky header** | Set `<thead>` to `position: sticky; top: 0; z-index: 1` inside the table's scroll container. The container becomes `max-h-[calc(100vh-220px)] overflow-auto` for long lists. |
| 6 | **Row selection count** | When `selectable` and at least one row is selected, render a thin (32 px) bulk-action bar above the header: `<n> selected   [Bulk action 1] [Bulk action 2]   [Clear]`. Already partially wired via `bulkActions`; just complete the visual. |
| 7 | **Context menu (right-click)** | New `rowContextMenu?: (row: T) => MenuItem[]` prop. On `contextmenu`, render a 12 px-radius popover at cursor coordinates. Default items provided by the table: "Open in new tab", "Copy ID". Page can append. |
| 8 | **Inline editing** | New `Column<T>.editable?: { type: 'number' \| 'text' \| 'select'; options?: ...; onSave: (row, value) => Promise }`. Cell shows value + small pencil icon on hover; click → inline input; `Enter` submits, `Esc` cancels; spinner inside cell during save. |

### 6.3 Files to create

| Path | Purpose |
|---|---|
| `spa/src/stores/tablePrefsStore.ts` | Zustand store with `localStorage` persist. Shape: `{ [tableKey]: { density, hiddenColumns: string[], columnWidths: Record<string, number> } }`. **No PII** — only column metadata. |
| `spa/src/components/ui/RowContextMenu.tsx` | Popover positioned at cursor; closes on outside click, `Esc`, route change. |
| `spa/src/components/ui/InlineEditCell.tsx` | Generic inline editor used by `editable` column type. |
| `spa/src/components/ui/DataTable.test.tsx` | Vitest tests covering: pinning, expand, hidden columns, persistence round-trip. |

### 6.4 Files to edit

- [`spa/src/components/ui/DataTable.tsx`](../spa/src/components/ui/DataTable.tsx:1): bulk of work.
- New optional `tableKey: string` prop to identify the table for prefs persistence. When absent, prefs are session-only (in-memory).
- One reference rollout: [`spa/src/pages/hr/employees/index.tsx`](../spa/src/pages/hr/employees/index.tsx:1) — sets `tableKey="hr.employees.list"`, pins `employee_no` left, adds `renderExpanded` showing department/position/contact in a thin sub-row, adds a context menu with "Open in new tab" + "Copy employee no".

### 6.5 Backwards compatibility

All existing `<DataTable>` usages (~30 list pages) must continue to render identically — every new prop is optional and falls through to current behavior. Verified by:

- Running existing tests unchanged.
- Visual smoke test on three other list pages (e.g. `/inventory/items`, `/accounting/vendors`, `/payroll/periods`) confirming no layout shift.

### 6.6 Design-system alignment

Per [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:472):

- Row height stays 32 px default (`h-8`); 28 px compact; 40 px spacious.
- Header still 10 px uppercase muted, `font-medium`.
- Numbers stay `font-mono tabular-nums`.
- Pinned-column boundary uses 0.5 px `--border-default` (no shadow — see [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:222) "almost no shadows").
- Context menu: 6 px radius, 0.5 px border, `bg-elevated`, 4 px vertical padding, items 28 px tall, 12 px font-size, hover `bg-subtle`.

### 6.7 Acceptance criteria

- [ ] Pinned `employee_no` stays visible while horizontally scrolling a wide list.
- [ ] Row expand chevron toggles a sub-row; no flicker; only one expand per click.
- [ ] Resizing a column persists across reloads (verified via store key).
- [ ] Hiding "Department" column persists; restoring with "Customize columns" works.
- [ ] Sticky header stays visible vertically scrolling 50+ rows.
- [ ] Right-click on a row opens the context menu; "Open in new tab" opens the detail page in a new tab.
- [ ] Inline-edit on `pay_type` (or some demo column) saves and shows error state on API failure.
- [ ] All existing `DataTable` consumers render unchanged.
- [ ] Quality gate passes.

---

## 7. Task X5 — Loading & Transition Polish

### 7.1 Files to create

| Path | Purpose |
|---|---|
| `spa/src/components/ui/RouteTransition.tsx` | Wraps the routed `<Outlet />` with a 150 ms fade (`opacity 0 → 1`, `ease cubic-bezier(0.4, 0, 0.2, 1)`). Respects `prefers-reduced-motion` (skips animation). Mounted inside `AppLayout` so the sidebar/topbar do **not** fade — only the page content. |
| `spa/src/hooks/useIsRefreshing.ts` | Returns true when any TanStack Query under a given queryKey prefix is `isFetching` but not `isLoading` (i.e. background refetch). |
| `spa/src/components/layout/RefreshingIndicator.tsx` | Small "Refreshing…" pill (`text-2xs text-muted` with a 10 px spinning [`Spinner`](../spa/src/components/ui/Spinner.tsx:1)) rendered inside `PageHeader`'s subtitle slot. |
| `spa/src/lib/optimistic.ts` | Helper `optimisticUpdate(queryClient, queryKey, updater)` that snapshots, applies, and rolls back on error. Used by status-toggle mutations. |

### 7.2 Files to edit

- [`spa/src/App.tsx`](../spa/src/App.tsx:1):
  - Wrap module-group routes in their own `<Suspense fallback={<PageBodySkeleton />}>` so the layout shell (sidebar + topbar) is rendered immediately. Today the entire app sits behind a single `<Suspense fallback={<FullPageLoader />}>` (line 9–10 of App.tsx) which is the jarring transition the task wants removed.
  - Place `<RouteTransition>` immediately inside [`AppLayout`](../spa/src/layouts/AppLayout.tsx:1)'s main content slot, not in `App.tsx`, so auth pages keep their own behavior.
- [`spa/src/components/layout/PageHeader.tsx`](../spa/src/components/layout/PageHeader.tsx:1): expose a `refreshingQueryKey?: string[]` prop and render `<RefreshingIndicator>` when truthy and refetching. Consumers passing `refreshingQueryKey={['employees', filters]}` get the indicator for free.
- Sweep audit: search for `<Spinner />` rendered as a page-level state and replace with the appropriate skeleton from [`Skeleton`](../spa/src/components/ui/Skeleton.tsx:1). Spinners must remain only inside buttons (loading state) and inside the new RefreshingIndicator. Search command: `rg "Spinner" spa/src/pages | rg -v "Button"`.
- Sample optimistic-update rollout: status-toggle mutation on Approved Supplier list ([`spa/src/pages/purchasing/approved-suppliers.tsx`](../spa/src/pages/purchasing/approved-suppliers.tsx:1)) — uses `optimisticUpdate` helper. Demonstrates the pattern; expand later in a follow-up sweep.

### 7.3 Animation discipline

Per [`docs/DESIGN-SYSTEM.md`](../docs/DESIGN-SYSTEM.md:236):

- 150 ms fade — allowed.
- No card-hover-lift, no count-up, no bouncy easing, no entrance animations on table rows. The plan does not introduce any of these.
- All animations must be wrapped in the existing `prefers-reduced-motion` guard.

### 7.4 Acceptance criteria

- [ ] Navigating between two SPA routes produces a 150 ms fade of page content; sidebar and topbar do **not** fade.
- [ ] During background refetch (e.g. invalidate-on-tab-focus), the page header shows "Refreshing…" — table content does not flash to skeleton.
- [ ] Toggling a supplier's `is_active` updates the row immediately; on simulated API error, row reverts and error toast shows.
- [ ] No `<Spinner />` remains as a page-level fallback; only inside buttons / refreshing indicator.
- [ ] `prefers-reduced-motion: reduce` disables the route fade.
- [ ] Quality gate passes.

---

## 8. Cross-cutting checklist (run at end of every task)

Per [`CLAUDE.md`](../CLAUDE.md:523) Final checklist + [`docs/PATTERNS.md`](../docs/PATTERNS.md:1716):

- [ ] No new Bearer-token paths; no auth-related state in `localStorage`.
- [ ] Numbers still use `font-mono tabular-nums`.
- [ ] Status fields still use `<Chip>` with semantic variant.
- [ ] Routes still wrapped in `AuthGuard` + `ModuleGuard` + `PermissionGuard`.
- [ ] Pages still lazy-loaded.
- [ ] Geist font preserved; Geist Mono for numbers/IDs/dates.
- [ ] Table rows still 32 px default, headers still uppercase letter-spaced.
- [ ] No color introduced to canvas, body text, borders, sidebars.
- [ ] `cd spa && npm run lint && npm run typecheck && npm run test -- --run` all green.

---

## 9. Execution order & PR strategy

Open one PR per task to keep review surface manageable:

```
1. PR feat/x1-keyboard-shortcuts
2. PR feat/x2-smart-forms        (foundation + 1 reference form)
3. PR feat/x3-empty-states       (foundation + sweep of all 30+ list pages)
4. PR feat/x4-datatable-enhancements (foundation + 1 reference list page)
5. PR feat/x5-transition-polish
```

Per [`.roo/skills/kwatog/commit-and-pr.md`](../.roo/skills/kwatog/commit-and-pr.md:1):

- Branch: `feat/x<n>-<slug>`.
- Conventional commit per task: `feat(spa): X<n> — <description>`.
- PR body lists every modified file, includes the quality-gate output, references this plan + the matching section in [`docs/NEW-TASKS-V2.md`](../docs/NEW-TASKS-V2.md:1042).
- Target `kwat0g/kwatog`, wait for CI green before requesting review.

---

## 10. Risks & mitigations

| Risk | Mitigation |
|---|---|
| `react-hotkeys-hook` swallows shortcuts inside the `CommandPalette` input | The library skips by default when `enableOnFormTags=false`; verified in `useHotkeys` defaults. Add explicit `enableOnFormTags: false` to make intent obvious. |
| Auto-saved drafts leak salaries / TINs to disk | Hard-coded blocklist regex in `useFormDraftAutosave`; unit test asserts blocklisted fields never appear in the persisted payload. |
| `DataTable` refactor breaks 30+ list pages | All new props optional; existing tests pass; visual smoke on 3 sample pages; gradual reference rollout (one page) leaves the rest untouched. |
| Route transition perceived as slowdown on fast SSDs | Duration = 150 ms (fast tier in design system); skipped under `prefers-reduced-motion`; can be lowered to 100 ms if QA flags it. |
| Mass empty-state copy edits introduce typos / inconsistent voice | All copy lives in one file `emptyStateCopy.ts` with snapshot test; reviewed by HR/Ops voice in PR. |

---

## 11. Definition of Done (whole series)

- All five tasks shipped, each via its own PR with CI green.
- Quality gate (`lint && typecheck && test`) green on each PR head and on `main` after merge.
- Screenshot/GIF in each PR demonstrating the polish.
- This plan updated to reflect any deltas discovered during execution (call `create_plan` again with the same title to version it).
