---
name: eloquent-performance
description: Use when writing or modifying any Eloquent query that lists rows, joins relations, or runs in a hot path. N+1 queries are the most common kwatog perf bug; this skill codifies eager loading, count caching, indexes, and detection.
---

# Eloquent Performance (kwatog)

## Iron law

**No `->paginate()` or list query without `->with([...])` for every relation accessed in the loop.**

If a Resource accesses `$item->vendor->name`, the underlying query MUST eager-load `vendor`. Otherwise: N+1.

## Detect N+1 in development

```bash
cd api
# Quick log-based check:
DB_LOG=true php artisan tinker --execute="DB::enableQueryLog(); App\\Modules\\Accounting\\Models\\Vendor::with('invoices')->take(5)->get()->each(fn(\$v) => \$v->invoices->count()); dump(count(DB::getQueryLog()));"
```

If you see one query per iteration, you have N+1. The fix is `->with(...)` or `->withCount(...)`.

## Patterns to copy

### List endpoint with relations

```php
public function list(array $filters): LengthAwarePaginator
{
    return Product::query()
        ->with(['category', 'taxClass'])     // every relation the Resource touches
        ->withCount(['lineItems'])           // counts go in withCount
        ->when($filters['search'] ?? null, fn ($q, $term) =>
            $q->where(fn ($qq) =>
                $qq->where('part_number', SearchOperator::like(), "%{$term}%")
                   ->orWhere('name', SearchOperator::like(), "%{$term}%")
            )
        )
        ->orderBy('created_at', 'desc')
        ->paginate($filters['per_page'] ?? 20);
}
```

### Detail endpoint

```php
public function show(Product $product): Product
{
    return $product->load(['category', 'taxClass', 'priceAgreements.customer']);
}
```

`load()` on a single model is the equivalent of `with()` on a query builder.

### Avoid `->get()->count()`

```php
// BAD
$total = User::all()->count();          // loads all rows into memory

// GOOD
$total = User::query()->count();         // SQL COUNT(*)
```

### Subquery for "has X" flags

The kwatog ProductService does this for "has BOM":

```php
$hasBomSubquery = "(SELECT 1 FROM bill_of_materials b
                   WHERE b.product_id = products.id AND b.is_active = true LIMIT 1)";
$q->selectRaw("products.*, COALESCE(({$hasBomSubquery}), 0) as has_bom_flag");
```

Cheaper than `withExists()` for tables with many rows. See [`api/app/Modules/CRM/Services/ProductService.php`](../../../api/app/Modules/CRM/Services/ProductService.php).

## Indexes

For any column used in `WHERE`, `ORDER BY`, or as a JOIN condition, there must be an index. Add it in the migration:

```php
$table->index(['status', 'created_at']);  // composite index for filter + sort
$table->index('email');                    // single-column for lookups
```

Foreign keys auto-index in MySQL/Postgres. Other filter columns do not.

## Pagination defaults

- API list endpoints default to 20 per page (sometimes 50). Cap at 100 via FormRequest validation.
- For SPA infinite scrolling, use cursor pagination (`->cursorPaginate()`) when the table is large; offset pagination becomes slow past page 100.

## Hot-path queries

A "hot path" is anything called per request on every page (the dashboard summary, alert counts, RBAC check). Treat these as sub-50ms targets:

- Cache when the value changes infrequently (`Cache::remember(...)`).
- Avoid `->withCount` of large tables on every request; precompute via a denormalized counter column updated by Events/Observers.
- Profile with the database query log (`DB::enableQueryLog`) or Laravel Telescope locally.

## SPA-side performance

- **Stable react-query keys.** A query key with an object reference re-fetches on every render: `['vendors', { active: true }]` will refetch unless the object identity is stable. Prefer primitives or sorted spread: `['vendors', filters.active, filters.search]`.
- **Pagination + filters in the URL** (search params), not in component state, so back/forward works and links are shareable.
- **Avoid big arrays in zustand stores.** Server data lives in react-query. Stores hold UI state (sidebar collapsed, theme, current user).

## Verification

```bash
cd api
php artisan test --filter=<your filter>
# Add a perf assertion if needed:
# $this->assertDatabaseQueryCount(N);  // available with laravel-test-helpers
```

For SPA, open DevTools network tab and confirm the list page makes ONE list request, not many. React Query devtools show stale/fresh state and refetch counts.

Then run [`code-quality-gate.md`](code-quality-gate.md).

## Common mistakes

- **`->all()` instead of `->paginate()`** in list endpoints. Returns the entire table.
- **`relation` accessed in a Resource without `with`/`load`.** Classic N+1.
- **Missing index** on a column used in `where(...)`. Sequential scan on every request.
- **`Cache::remember` with a wide key** (per-user) for data shared across users. Cache stampede.
- **Trusting `Eloquent::query()->where(...)->raw(...)` with user input.** Use bindings, never string concat.
