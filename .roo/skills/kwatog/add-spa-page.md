---
name: add-spa-page
description: Use when adding or modifying any SPA page under spa/src/pages/. Codifies the file order, the canonical patterns to copy from PATTERNS.md, and the post-write verification specific to kwatog's React 18 + Vite + react-query + react-hook-form + zod + Tailwind stack.
---

# Add or Modify an SPA Page (kwatog)

## Source of truth

The shape of every SPA layer (API client, types, list page, detail page, create form, edit form, filter bar, error boundary, page states, toast, route setup) is locked in [`docs/PATTERNS.md`](../../../docs/PATTERNS.md) sections 8-21. **Read those sections first**, then come back here for the workflow.

This skill adds the order and verification - PATTERNS.md is the what.

## File order (do not skip steps)

For a CRUD-shaped feature (the dominant pattern in kwatog):

1. **Types** at `spa/src/types/<module>.ts`.
   - Match the API Resource exactly. PATTERNS.md section 9.

2. **API client** at `spa/src/api/<module>/<resource>.ts`.
   - Functions: `list(params)`, `show(id)`, `create(data)`, `update(id, data)`, `remove(id)`.
   - Use the shared axios instance. PATTERNS.md section 8.

3. **List page** at `spa/src/pages/<module>/index.tsx`.
   - DataTable + FilterBar + permission-gated buttons. PATTERNS.md sections 10, 17, 19, 20.

4. **Detail page** at `spa/src/pages/<module>/detail.tsx`.
   - PATTERNS.md section 11.

5. **Form** - prefer a single `form.tsx` with `mode: 'create' | 'edit'` prop, then thin `create.tsx` and `edit.tsx` wrappers. The Vendor module is the canonical example: [`spa/src/pages/accounting/vendors/form.tsx`](../../../spa/src/pages/accounting/vendors/form.tsx), [`create.tsx`](../../../spa/src/pages/accounting/vendors/create.tsx), [`edit.tsx`](../../../spa/src/pages/accounting/vendors/edit.tsx).
   - PATTERNS.md sections 12, 13.

6. **Routes** in [`spa/src/App.tsx`](../../../spa/src/App.tsx). PATTERNS.md section 21.

7. **Sidebar / dashboard link** if the page is user-reachable. Update [`spa/src/lib/dashboardLinks.ts`](../../../spa/src/lib/dashboardLinks.ts) and the relevant layout if needed.

8. **Tests** - vitest under `spa/src/**/__tests__/` or co-located `*.test.tsx`. See [`testing-strategy.md`](testing-strategy.md).

## Required conventions (kwatog-specific)

These are easy to miss if you don't already know them:

### Forms

- **react-hook-form + zod** via `@hookform/resolvers`. Define the zod schema, use `zodResolver`, type via `z.infer<typeof schema>`.
- **Validation errors from the API** must be surfaced through [`spa/src/lib/formErrors.ts`](../../../spa/src/lib/formErrors.ts) using `onFormInvalid`. Do not manually write `setError` mapping.
- **Numeric inputs** must use [`numberInputProps`](../../../spa/src/lib/numberInput.ts) from `@/lib/numberInput`, never raw `<input type="number" />`. This is for Philippine peso conventions and consistent step/min handling.
- **Dates** use [`@/lib/formatDate`](../../../spa/src/lib/formatDate.ts). Currency uses [`formatPeso`](../../../spa/src/lib/formatNumber.ts).

### Data fetching

- **react-query** with stable query keys: `['<module>', '<resource>', ...params]`. Mutations call `qc.invalidateQueries({ queryKey: ['<module>', '<resource>'] })` on success.
- **Toasts** via `react-hot-toast` `toast.success(...)` / `toast.error(...)`. PATTERNS.md section 20.
- See [`spa-state-and-data-fetching.md`](spa-state-and-data-fetching.md) for keying / cache-invalidation conventions.

### Permission gating in the UI

```tsx
import { usePermission } from '@/hooks/usePermission';
const { can } = usePermission();
{can('crm.products.manage') && <Button onClick={...}>Edit</Button>}
```

Never rely on UI-only gating for security - the API enforces it. UI gating is for UX (avoid showing buttons that 403). Both must be in place.

### Loading, empty, error, disabled states

PATTERNS.md section 19 calls these "the #1 cause of sloppy UI." Every page needs all four. Typical primitives: `<SkeletonTable />`, `<EmptyState />`, error boundary, disabled buttons during `mutation.isPending`.

### Components

Use design-system primitives from `@/components/ui/` (Button, Input, Switch, Textarea, Panel, DataTable, FilterBar, Chip, EmptyState, SkeletonTable, ...) before writing new ones. If you do need a new primitive, it goes in `@/components/ui/`, not in a feature folder.

## Verification before claiming done

```bash
cd spa
npm run typecheck     # zero errors on touched files
npm run lint          # 0 warnings (--max-warnings 0 enforced)
npm run test -- --run # vitest one-shot
npm run build         # vite production build must succeed
```

Manual smoke (if running the stack):

- Navigate to the new page. Verify list, filter, create, edit, delete flows.
- Open DevTools network tab. Verify the right query keys, no duplicate requests, errors show toasts.
- Toggle a user without the relevant permission. Verify the UI hides the gated buttons AND the API returns 403 if you try to call directly.

Then run the full [`code-quality-gate.md`](code-quality-gate.md).

## Common mistakes (do NOT do these)

- **Inventing your own form pattern** instead of following PATTERNS.md sections 12/13. Sloppy field rendering, missing disabled states, custom validation error mapping. Copy the Vendor form and change names/fields/schema.
- **Manual validation error mapping** instead of `onFormInvalid` from [`formErrors.ts`](../../../spa/src/lib/formErrors.ts).
- **Raw `<input type="number" />`** instead of `numberInputProps`.
- **Hard-coded currency / date format** instead of `formatPeso` / `formatDate`.
- **Missing the empty/error/loading states.** PATTERNS.md section 19 is mandatory.
- **No `qc.invalidateQueries` on mutation success.** Stale list views.
- **Stable query keys with object identity** (`{filters}` re-references). Always pass primitives or sorted spread.
- **Adding the route to a random place in App.tsx.** Match the existing module groupings.
- **Skipping `usePermission` gates.** Even if the API enforces it, the UI must hide unreachable buttons.
- **Importing from a feature folder into another feature folder.** Cross-feature imports go through `@/lib`, `@/components/ui`, `@/api`, or `@/types`.

## When the gate is honestly not applicable

Pure documentation or storybook changes that don't ship to users. Otherwise, the gate applies.
