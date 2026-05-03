<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 75. Cross-module search.
 *
 * Returns grouped results scoped to what the caller is permitted to view.
 * Backed by Postgres ilike (Meilisearch-ready: swap the per-source query body
 * for a Scout::search() when scout indexes are populated).
 *
 * Each result has: id (hash), label, sublabel, url, group.
 */
class GlobalSearchService
{
    /** @return array<int, array{group:string, label:string, items:array<int, array<string,mixed>>}> */
    public function search(User $user, string $query, int $perGroup = 5): array
    {
        $term = '%'.trim($query).'%';
        if (mb_strlen(trim($query)) < 2) return [];
        $like = SearchOperator::like();
        $groups = [];

        // Employees
        if ($user->can('hr.employees.view') && Schema::hasTable('employees')) {
            $rows = DB::table('employees')
                ->select('id', 'employee_no', 'first_name', 'last_name')
                ->where(fn ($q) => $q
                    ->where('employee_no', $like, $term)
                    ->orWhere('first_name', $like, $term)
                    ->orWhere('last_name',  $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Employees', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => trim(($r->first_name ?? '').' '.($r->last_name ?? '')),
                'sublabel' => $r->employee_no,
                'url'      => '/hr/employees/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        // Products
        if ($user->can('crm.products.view') && Schema::hasTable('products')) {
            $rows = DB::table('products')
                ->select('id', 'part_number', 'name')
                ->where(fn ($q) => $q
                    ->where('part_number', $like, $term)
                    ->orWhere('name', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Products', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->part_number,
                'url'      => '/crm/products/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        // Customers
        if ($user->can('accounting.customers.view') && Schema::hasTable('customers')) {
            $rows = DB::table('customers')
                ->select('id', 'name', 'tin')
                ->where('name', $like, $term)
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Customers', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->tin,
                'url'      => '/accounting/customers/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        // Vendors
        if ($user->can('accounting.vendors.view') && Schema::hasTable('vendors')) {
            $rows = DB::table('vendors')
                ->select('id', 'name', 'tin')
                ->where('name', $like, $term)
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Vendors', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->tin,
                'url'      => '/accounting/vendors/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        // Sales orders
        if ($user->can('crm.sales_orders.view') && Schema::hasTable('sales_orders')) {
            $rows = DB::table('sales_orders')
                ->select('id', 'so_number', 'status')
                ->where('so_number', $like, $term)
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Sales Orders', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => $r->so_number,
                'sublabel' => $r->status,
                'url'      => '/crm/sales-orders/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        // Purchase orders
        if ($user->can('purchasing.po.view') && Schema::hasTable('purchase_orders')) {
            $rows = DB::table('purchase_orders')
                ->select('id', 'po_number', 'status')
                ->where('po_number', $like, $term)
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Purchase Orders', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => $r->po_number,
                'sublabel' => $r->status,
                'url'      => '/purchasing/purchase-orders/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        // Work orders
        if ($user->can('production.work_orders.view') && Schema::hasTable('work_orders')) {
            $rows = DB::table('work_orders')
                ->select('id', 'wo_number', 'status')
                ->where('wo_number', $like, $term)
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Work Orders', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => $r->wo_number,
                'sublabel' => $r->status,
                'url'      => '/production/work-orders/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        // Invoices
        if ($user->can('accounting.invoices.view') && Schema::hasTable('invoices')) {
            $rows = DB::table('invoices')
                ->select('id', 'invoice_number', 'status')
                ->where('invoice_number', $like, $term)
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Invoices', $rows->map(fn ($r) => [
                'id'       => app('hashids')->encode((int) $r->id),
                'label'    => $r->invoice_number,
                'sublabel' => $r->status,
                'url'      => '/accounting/invoices/'.app('hashids')->encode((int) $r->id),
            ])->all());
        }

        return array_values(array_filter($groups, fn ($g) => count($g['items']) > 0));
    }

    private function wrap(string $label, array $items): array
    {
        return ['group' => strtolower(str_replace(' ', '_', $label)), 'label' => $label, 'items' => $items];
    }
}
