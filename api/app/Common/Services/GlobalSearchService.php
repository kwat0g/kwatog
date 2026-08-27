<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Support\DepartmentScope;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Quality\Models\NonConformanceReport;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-module search.
 *
 * Returns grouped results scoped to what the caller is permitted to view.
 * Backed by Postgres `ilike`. This used to say "Meilisearch-ready — swap the
 * per-source query body for a Scout::search() when Scout indexes are
 * populated", which described an intention rather than the system: no model
 * ever took the `Searchable` trait, `config/scout.php` was never published, and
 * nothing indexed anything. The engine, its two Composer packages and its
 * container were removed on 2026-08-20. Postgres `ilike` is the search, not a
 * placeholder for it; adding a real engine later means adding it, not swapping
 * a line.
 *
 * Each result has: id (hash), label, sublabel, status, amount?, url, group, type.
 *
 * ## Visibility contract — M009-F01
 *
 * A module permission answers "may this user open this screen at all". It does
 * NOT answer "which rows". Every group here used to run an unscoped
 * `DB::table()` query behind a single broad `can()` check, so search returned
 * rows the caller's own list page hides: a `department_head` holds
 * `hr.employees.view` and `purchasing.view` but not `hr.employees.view_sensitive`
 * or `purchasing.po.approve`, and could enumerate every employee in the company
 * and every purchase order regardless of department or authorship.
 *
 * Two rules therefore hold for every group below, and for any group added later:
 *
 *   1. Queries start from the **Eloquent model**, never `DB::table()`. The model
 *      carries `SoftDeletes`, so archived rows are excluded by the same global
 *      scope the module lists rely on (M009-F03). Eight of the eleven searched
 *      tables are soft-deletable; `DB::table()` saw all of their tombstones.
 *   2. Any group whose list service applies a row-level scope applies the SAME
 *      scope here, through the SAME shared helper — currently `DepartmentScope`
 *      for employees and purchase orders. Do not hand-roll a role check.
 *
 * ## Matching semantics — M009-F04
 *
 * Input is matched as a LITERAL case-insensitive substring: `%` and `_` are
 * escaped by `SearchOperator::contains()`, so there is no user-facing wildcard
 * syntax to abuse. Results are ranked deterministically (exact identifier,
 * identifier prefix, exact name, name prefix, substring) and tie-broken on the
 * primary key, so an exact `PO-202604-0015` can never be crowded out of the
 * five-row window by coincidental substring hits.
 *
 * ## Query budget — M009-F04
 *
 * One SELECT plus one `Schema::hasTable` probe per permitted group, capped by
 * `MAX_SOURCE_QUERIES`. Every SELECT is `LIMIT $perGroup`, so the row ceiling
 * for one request is `MAX_SOURCE_QUERIES * $perGroup`. Measured on the dev
 * dataset (200 employees, single-digit orders) as a system_admin — the widest
 * possible caller, all eleven groups active: 22 queries, 22–50 ms wall clock.
 *
 * A leading-wildcard `ILIKE` cannot use a B-tree index, so each group is a
 * sequential scan of its table (confirmed by `EXPLAIN ANALYZE`: 2.2 ms over 200
 * employee rows). That is acceptable at this scale and will NOT be at
 * production scale on the transaction tables. The fix is a trigram (`pg_trgm`
 * GIN) or full-text index — deliberately not added here: it needs `CREATE
 * EXTENSION` rights, spans eleven tables owned by other modules, and the index
 * choice should be driven by a plan measured on production-sized data, which
 * this environment does not have.
 *
 * Adding a group widens the per-request database cost for every caller who
 * holds its permission — raise the constant deliberately, and keep
 * `GlobalSearchTest` in step.
 */
class GlobalSearchService
{
    /**
     * Source queries this service may issue for one term — one per searchable
     * group. A documented ceiling rather than a runtime guard: the point is that
     * a twelfth group is a deliberate widening of every caller's request budget.
     */
    public const MAX_SOURCE_QUERIES = 11;

    /** @return array<int, array{group:string, label:string, type:string, items:array<int, array<string,mixed>>}> */
    public function search(User $user, string $query, int $perGroup = 5): array
    {
        $raw = trim($query);
        if (mb_strlen($raw) < 2) return [];

        $term = SearchOperator::contains($raw);
        $like = SearchOperator::like();
        $h    = app('hashids');
        $groups = [];

        // Employees -------------------------------------------------------------
        if ($user->hasPermission('hr.employees.view') && Schema::hasTable('employees')) {
            $q = Employee::query()
                ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->leftJoin('positions', 'positions.id', '=', 'employees.position_id')
                ->select('employees.id', 'employees.employee_no', 'employees.first_name',
                    'employees.middle_name', 'employees.last_name', 'employees.status',
                    'departments.name as department_name', 'positions.title as position_title')
                ->where(fn ($w) => $w
                    ->where('employees.employee_no', $like, $term)
                    ->orWhere('employees.first_name', $like, $term)
                    ->orWhere('employees.middle_name', $like, $term)
                    ->orWhere('employees.last_name',  $like, $term));

            // The employee list's row scope, verbatim (EmployeeService::baseQuery).
            // Columns are table-qualified because of the joins above — an
            // unqualified `department_id` would be ambiguous the day `positions`
            // grows one.
            DepartmentScope::apply(
                $q,
                $user,
                viewAllPermission: 'hr.employees.view_sensitive',
                departmentPermission: 'hr.employees.view',
                deptColumn: 'employees.department_id',
                selfColumn: 'employees.id',
                selfId: $user->employee_id,
            );

            $rows = $this->rank($q, 'employees.employee_no', [
                'employees.last_name', 'employees.middle_name',
            ], $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Employees', 'employee', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => trim(($r->first_name ?? '').' '.($r->last_name ?? '')),
                'sublabel' => trim((string) ($r->employee_no ?? '').($r->department_name ? ' · '.$r->department_name : '').($r->position_title ? ' · '.$r->position_title : '')),
                'status'   => $this->scalar($r->status),
                'url'      => '/hr/employees/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Sales orders ----------------------------------------------------------
        if ($user->hasPermission('crm.sales_orders.view') && Schema::hasTable('sales_orders')) {
            $q = SalesOrder::query()
                ->leftJoin('customers', 'customers.id', '=', 'sales_orders.customer_id')
                ->select('sales_orders.id', 'sales_orders.so_number', 'sales_orders.status',
                    'sales_orders.total_amount', 'customers.name as customer_name')
                ->where(fn ($w) => $w
                    ->where('sales_orders.so_number', $like, $term)
                    ->orWhere('customers.name', $like, $term));

            $rows = $this->rank($q, 'sales_orders.so_number', 'customers.name', $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Sales Orders', 'sales_order', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->so_number,
                'sublabel' => $r->customer_name,
                'status'   => $this->scalar($r->status),
                'amount'   => $this->scalar($r->total_amount),
                'url'      => '/crm/sales-orders/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Purchase orders -------------------------------------------------------
        if ($user->hasPermission('purchasing.view') && Schema::hasTable('purchase_orders')) {
            $q = PurchaseOrder::query()
                ->leftJoin('vendors', 'vendors.id', '=', 'purchase_orders.vendor_id')
                ->select('purchase_orders.id', 'purchase_orders.po_number', 'purchase_orders.status',
                    'purchase_orders.total_amount', 'vendors.name as vendor_name')
                ->where(fn ($w) => $w
                    ->where('purchase_orders.po_number', $like, $term)
                    ->orWhere('vendors.name', $like, $term));

            // The purchase-order list's row scope (PurchaseOrderService::list),
            // re-expressed through the shared helper: PO approvers and admins see
            // everything; a PR approver additionally sees their department's POs
            // through the linked PR; everyone else sees only what they created.
            // Note `created_by` MUST be qualified — `vendors` has a column of the
            // same name (migration 0222).
            DepartmentScope::apply(
                $q,
                $user,
                viewAllPermission: 'purchasing.po.approve',
                departmentPermission: 'purchasing.pr.approve',
                deptColumn: 'department_id',
                selfColumn: 'purchase_orders.created_by',
                selfId: $user->id,
                deptRelation: 'purchaseRequest',
            );

            $rows = $this->rank($q, 'purchase_orders.po_number', 'vendors.name', $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Purchase Orders', 'purchase_order', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->po_number,
                'sublabel' => $r->vendor_name,
                'status'   => $this->scalar($r->status),
                'amount'   => $this->scalar($r->total_amount),
                'url'      => '/purchasing/purchase-orders/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Work orders -----------------------------------------------------------
        if ($user->hasPermission('production.work_orders.view') && Schema::hasTable('work_orders')) {
            $q = WorkOrder::query()
                ->leftJoin('products', 'products.id', '=', 'work_orders.product_id')
                ->leftJoin('machines', 'machines.id', '=', 'work_orders.machine_id')
                ->select('work_orders.id', 'work_orders.wo_number', 'work_orders.status',
                    'products.name as product_name', 'products.part_number', 'machines.name as machine_name')
                ->where(fn ($w) => $w
                    ->where('work_orders.wo_number', $like, $term)
                    ->orWhere('products.name', $like, $term)
                    ->orWhere('products.part_number', $like, $term)
                    ->orWhere('machines.name', $like, $term));

            $rows = $this->rank($q, 'work_orders.wo_number', 'products.name', $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Work Orders', 'work_order', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->wo_number,
                'sublabel' => trim((string) ($r->product_name ?? '').($r->machine_name ? ' · '.$r->machine_name : '')),
                'status'   => $this->scalar($r->status),
                'url'      => '/production/work-orders/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Invoices --------------------------------------------------------------
        if ($user->hasPermission('accounting.invoices.view') && Schema::hasTable('invoices')) {
            $q = Invoice::query()
                ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
                ->select('invoices.id', 'invoices.invoice_number', 'invoices.status',
                    'invoices.total_amount', 'customers.name as customer_name')
                ->where(fn ($w) => $w
                    ->where('invoices.invoice_number', $like, $term)
                    ->orWhere('customers.name', $like, $term));

            $rows = $this->rank($q, 'invoices.invoice_number', 'customers.name', $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Invoices', 'invoice', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->invoice_number,
                'sublabel' => $r->customer_name,
                'status'   => $this->scalar($r->status),
                'amount'   => $this->scalar($r->total_amount),
                'url'      => '/accounting/invoices/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Bills -----------------------------------------------------------------
        if ($user->hasPermission('accounting.bills.view') && Schema::hasTable('bills')) {
            $q = Bill::query()
                ->leftJoin('vendors', 'vendors.id', '=', 'bills.vendor_id')
                ->select('bills.id', 'bills.bill_number', 'bills.status',
                    'bills.total_amount', 'vendors.name as vendor_name')
                ->where(fn ($w) => $w
                    ->where('bills.bill_number', $like, $term)
                    ->orWhere('vendors.name', $like, $term));

            $rows = $this->rank($q, 'bills.bill_number', 'vendors.name', $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Bills', 'bill', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->bill_number,
                'sublabel' => $r->vendor_name,
                'status'   => $this->scalar($r->status),
                'amount'   => $this->scalar($r->total_amount),
                'url'      => '/accounting/bills/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Products --------------------------------------------------------------
        if ($user->hasPermission('crm.products.view') && Schema::hasTable('products')) {
            $q = Product::query()
                ->select('products.id', 'products.part_number', 'products.name')
                ->where(fn ($w) => $w
                    ->where('products.part_number', $like, $term)
                    ->orWhere('products.name', $like, $term));

            $rows = $this->rank($q, 'products.part_number', 'products.name', $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Products', 'product', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->part_number,
                'status'   => null,
                'url'      => '/crm/products/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Items (inventory) -----------------------------------------------------
        if ($user->hasPermission('inventory.view') && Schema::hasTable('items')) {
            $q = Item::query()
                ->select('items.id', 'items.code', 'items.name', 'items.item_type')
                ->where(fn ($w) => $w
                    ->where('items.code', $like, $term)
                    ->orWhere('items.name', $like, $term));

            $rows = $this->rank($q, 'items.code', 'items.name', $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Items', 'item', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => trim((string) ($r->code ?? '').($r->item_type ? ' · '.$this->scalar($r->item_type) : '')),
                'status'   => null,
                'url'      => '/inventory/items/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Customers -------------------------------------------------------------
        //
        // M009-F02: `tin` is deliberately NOT selected. It used to be the
        // sublabel fallback whenever `contact_person` was empty, which put a
        // government identifier into a response gated on the *view* permission
        // while CustomerResource masks it for anyone without `.manage` — and
        // CommandPalette then persisted that sublabel to localStorage. (The
        // column is an `encrypted` cast, so the raw select actually emitted
        // ciphertext, not a readable TIN: a garbled sublabel rather than a
        // plaintext leak. Either way it has no business in a search result.)
        // `code` is the correct fallback: a non-sensitive business identifier
        // CustomerResource already returns unmasked.
        if ($user->hasPermission('accounting.customers.view') && Schema::hasTable('customers')) {
            $q = Customer::query()
                ->select('customers.id', 'customers.name', 'customers.code', 'customers.contact_person')
                ->where(fn ($w) => $w
                    ->where('customers.name', $like, $term)
                    ->orWhere('customers.contact_person', $like, $term));

            $rows = $this->rank($q, 'customers.name', null, $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Customers', 'customer', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->contact_person ?: $r->code,
                'status'   => null,
                'url'      => '/accounting/customers/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Vendors ---------------------------------------------------------------
        // See the customers note: `tin` is not selected. Vendors have no
        // non-sensitive code column, so an empty `contact_person` simply yields
        // no sublabel.
        if ($user->hasPermission('accounting.vendors.view') && Schema::hasTable('vendors')) {
            $q = Vendor::query()
                ->select('vendors.id', 'vendors.name', 'vendors.contact_person')
                ->where(fn ($w) => $w
                    ->where('vendors.name', $like, $term)
                    ->orWhere('vendors.contact_person', $like, $term));

            $rows = $this->rank($q, 'vendors.name', null, $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('Vendors', 'vendor', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->contact_person ?: null,
                'status'   => null,
                'url'      => '/accounting/vendors/'.$h->encode((int) $r->id),
            ])->all());
        }

        // NCRs ------------------------------------------------------------------
        if ($user->hasPermission('quality.ncr.view') && Schema::hasTable('non_conformance_reports')) {
            $q = NonConformanceReport::query()
                ->select('non_conformance_reports.id', 'non_conformance_reports.ncr_number',
                    'non_conformance_reports.status', 'non_conformance_reports.severity',
                    'non_conformance_reports.defect_description')
                ->where(fn ($w) => $w
                    ->where('non_conformance_reports.ncr_number', $like, $term)
                    ->orWhere('non_conformance_reports.defect_description', $like, $term));

            $rows = $this->rank($q, 'non_conformance_reports.ncr_number', null, $raw)
                ->limit($perGroup)->get();

            $groups[] = $this->wrap('NCRs', 'ncr', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->ncr_number,
                'sublabel' => $r->severity ? 'Severity: '.$this->scalar($r->severity) : null,
                'status'   => $this->scalar($r->status),
                'url'      => '/quality/ncrs/'.$h->encode((int) $r->id),
            ])->all());
        }

        return array_values(array_filter($groups, fn ($g) => count($g['items']) > 0));
    }

    /**
     * Deterministic relevance ordering — M009-F05.
     *
     * Every group used to be a bare `limit($perGroup)` with no ORDER BY, so
     * which five rows came back was whatever the query plan happened to emit
     * first. Searching an exact `PO-202604-0015` could return five coincidental
     * substring matches and drop the record the user typed, and repeating the
     * same search could return a different five.
     *
     * Rank: 0 exact identifier, 1 identifier prefix, 2 exact name, 3 name
     * prefix, 4 plain substring. Ties break on the identifier then the primary
     * key, so the window is stable across calls.
     *
     * $idColumn/$nameColumns are code constants, never user input; the term is
     * bound. A NULL column yields NULL from `ILIKE`, which is not true, so it
     * falls through to the next arm and lands on ELSE.
     *
     * @param  Builder<*>  $q
     * @return Builder<*>
     */
    private function rank(Builder $q, string $idColumn, string|array|null $nameColumns, string $raw): Builder
    {
        $like   = SearchOperator::like();
        $exact  = SearchOperator::exact($raw);
        $prefix = SearchOperator::startsWith($raw);

        $arms     = ["WHEN {$idColumn} {$like} ? THEN 0", "WHEN {$idColumn} {$like} ? THEN 1"];
        $bindings = [$exact, $prefix];
        $nameColumns = $nameColumns === null
            ? []
            : (is_array($nameColumns) ? $nameColumns : [$nameColumns]);

        foreach ($nameColumns as $nameColumn) {
            $arms[] = "WHEN {$nameColumn} {$like} ? THEN 2";
            $bindings[] = $exact;
        }
        foreach ($nameColumns as $nameColumn) {
            $arms[] = "WHEN {$nameColumn} {$like} ? THEN 3";
            $bindings[] = $prefix;
        }

        return $q->orderByRaw('CASE '.implode(' ', $arms).' ELSE 4 END', $bindings)
            ->orderBy($idColumn)
            ->orderBy($q->getModel()->getQualifiedKeyName());
    }

    /**
     * Flatten a column value to the string the palette contract promises.
     *
     * Moving off `DB::table()` means status/severity/type columns now arrive as
     * the model's cast type — usually a backed enum, sometimes a `decimal:2`
     * string object. The wire format must stay a plain string either way, or the
     * SPA's `chipVariantForStatus()` and `Number(amount)` both receive an object.
     */
    private function scalar(mixed $value): ?string
    {
        if ($value === null) return null;
        if ($value instanceof BackedEnum) return (string) $value->value;
        return (string) $value;
    }

    private function wrap(string $label, string $type, array $items): array
    {
        return [
            'group' => $type,
            'label' => $label,
            'type'  => $type,
            'items' => $items,
        ];
    }
}
