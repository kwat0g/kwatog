---
name: spa-state-and-data-fetching
description: Use when wiring data fetching, mutations, or state to any SPA page. Codifies kwatog's react-query keying, invalidation, and zustand-vs-server-state split.
---

# SPA State and Data Fetching (kwatog)

## The split

| Where data lives | Use |
|---|---|
| Server data (vendors, invoices, employees, anything from the API) | **react-query** (`useQuery`, `useMutation`) |
| UI state (sidebar collapsed, theme, auth user, current filter dropdown) | **zustand** stores under [`spa/src/stores/`](../../../spa/src/stores/) |
| URL state (filters, pagination, search) | **react-router** search params |
| Form state | **react-hook-form** |

Mixing these is the root of most "stale data" and "weird re-render" bugs. Server data does **not** belong in zustand.

## Query keys - the rule

Query keys are arrays of primitives or sorted objects. Identity stability matters:

```ts
// GOOD - primitives, stable across renders
useQuery({
  queryKey: ['accounting', 'vendors', filters.active, filters.search, page],
  queryFn:  () => vendorsApi.list({ active: filters.active, search: filters.search, page }),
});

// BAD - object identity changes every render -> infinite refetch
useQuery({
  queryKey: ['accounting', 'vendors', filters],
  queryFn:  () => vendorsApi.list(filters),
});
```

Hierarchy convention: `['<module>', '<resource>', ...params]`. Examples:

- `['accounting', 'vendors']` - whole list cache
- `['accounting', 'vendors', 'detail', id]` - specific vendor
- `['crm', 'products', 'list', filters.search, filters.is_active]` - filtered list

This hierarchy makes invalidation cheap.

## Mutations + invalidation

```tsx
const qc = useQueryClient();
const mutation = useMutation({
  mutationFn: (d: FormValues) => vendorsApi.create(d),
  onSuccess: () => {
    // Invalidate at the broadest level you actually care about.
    qc.invalidateQueries({ queryKey: ['accounting', 'vendors'] });
    toast.success('Vendor created.');
    navigate('/accounting/vendors');
  },
  onError: onFormInvalid(setError, { fallbackMessage: 'Could not save vendor.' }),
});
```

`onFormInvalid` from [`@/lib/formErrors`](../../../spa/src/lib/formErrors.ts) translates 422 validation responses into `setError` calls and triggers a fallback toast on other errors. **Use it for every form mutation.** Do not write manual error mapping.

## Loading and error UI

react-query exposes `isPending` (replacing `isLoading`), `isError`, `error`, `data`. Combine with the design-system primitives:

```tsx
if (isPending)              return <SkeletonTable rows={10} />;
if (isError)                return <EmptyState title="Failed to load" message={error.message} />;
if (data?.data.length === 0) return <EmptyState title="No vendors yet" actionLabel="Add vendor" onAction={...} />;
return <DataTable data={data.data} columns={...} />;
```

PATTERNS.md section 19 is the spec.

## Mutations: disable buttons during pending

```tsx
<Button type="submit" disabled={isSubmitting || mutation.isPending}>
  {mutation.isPending ? 'Saving...' : 'Save'}
</Button>
```

Missing this is the most common UX bug.

## Optimistic updates

Skip them unless the network is consistently >300ms or the action is user-frequency. They add complexity. When you do use them, follow the react-query optimistic update recipe with `onMutate`/`onError`/`onSettled`. Always test the rollback path.

## Cancellation

react-query handles request cancellation automatically when a key changes. Do not manually wrap fetches in `AbortController` unless the API supports it and you have a specific reason.

## Real-time (Echo / Reverb)

[`spa/src/lib/echo.ts`](../../../spa/src/lib/echo.ts) wires Laravel Echo to Pusher/Reverb. Use it for notifications, presence, and live counters. Subscriptions go in `useEffect`; ALWAYS clean up:

```tsx
useEffect(() => {
  const ch = echo.private(`alerts.${userId}`).listen('AlertCreated', (e) => {
    qc.invalidateQueries({ queryKey: ['alerts'] });
  });
  return () => { ch.stopListening('AlertCreated'); };
}, [userId, qc]);
```

Forgotten cleanup leaks subscriptions and triggers double invalidation.

## Zustand stores

Already in place: [`authStore`](../../../spa/src/stores/authStore.ts), [`sidebarStore`](../../../spa/src/stores/sidebarStore.ts), [`themeStore`](../../../spa/src/stores/themeStore.ts), [`errorLogStore`](../../../spa/src/stores/errorLogStore.ts). Match their shape (selectors, actions, persistence) when adding a new store.

Do **not** put server data in zustand. If you find yourself reaching for zustand to share an API response across pages, use a react-query key instead.

## Verification

```bash
cd spa
npm run typecheck
npm run lint
npm run test -- --run
```

Manual: open the page, check the network tab for duplicate requests, verify toasts on success/error, verify buttons disable during pending.

Then run [`code-quality-gate.md`](code-quality-gate.md).
