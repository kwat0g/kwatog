<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Support\SearchOperator;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-module search.
 *
 * Returns grouped results scoped to what the caller is permitted to view.
 * Backed by Postgres ilike (Meilisearch-ready: swap the per-source query body
 * for a Scout::search() when Scout indexes are populated).
 *
 * Each result has: id (hash), label, sublabel, status, amount?, url, group, type.
 */
class GlobalSearchService
{
    /** @return array<int, array{group:string, label:string, type:string, items:array<int, array<string,mixed>>}> */
    public function search(User $user, string $query, int $perGroup = 5): array
    {
        if (mb_strlen(trim($query)) < 2) return [];
        $term = '%'.trim($query).'%';
        $like = SearchOperator::like();
        $h    = app('hashids');
        $groups = [];

        // Employees -------------------------------------------------------------
        if ($user->can('hr.employees.view') && Schema::hasTable('employees')) {
            $rows = DB::table('employees as e')
                ->leftJoin('departments as d', 'd.id', '=', 'e.department_id')
                ->leftJoin('positions as p', 'p.id', '=', 'e.position_id')
                ->select('e.id', 'e.employee_no', 'e.first_name', 'e.last_name', 'e.status',
                    'd.name as department_name', 'p.title as position_title')
                ->where(fn ($q) => $q
                    ->where('e.employee_no', $like, $term)
                    ->orWhere('e.first_name', $like, $term)
                    ->orWhere('e.last_name',  $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Employees', 'employee', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => trim(($r->first_name ?? '').' '.($r->last_name ?? '')),
                'sublabel' => trim((string) ($r->employee_no ?? '').($r->department_name ? ' · '.$r->department_name : '').($r->position_title ? ' · '.$r->position_title : '')),
                'status'   => $r->status,
                'url'      => '/hr/employees/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Sales orders ----------------------------------------------------------
        if ($user->can('crm.sales_orders.view') && Schema::hasTable('sales_orders')) {
            $rows = DB::table('sales_orders as s')
                ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
                ->select('s.id', 's.so_number', 's.status', 's.total_amount', 'c.name as customer_name')
                ->where(fn ($q) => $q
                    ->where('s.so_number', $like, $term)
                    ->orWhere('c.name', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Sales Orders', 'sales_order', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->so_number,
                'sublabel' => $r->customer_name,
                'status'   => $r->status,
                'amount'   => $r->total_amount !== null ? (string) $r->total_amount : null,
                'url'      => '/crm/sales-orders/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Purchase orders -------------------------------------------------------
        if ($user->can('purchasing.po.view') && Schema::hasTable('purchase_orders')) {
            $rows = DB::table('purchase_orders as po')
                ->leftJoin('vendors as v', 'v.id', '=', 'po.vendor_id')
                ->select('po.id', 'po.po_number', 'po.status', 'po.total_amount', 'v.name as vendor_name')
                ->where(fn ($q) => $q
                    ->where('po.po_number', $like, $term)
                    ->orWhere('v.name', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Purchase Orders', 'purchase_order', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->po_number,
                'sublabel' => $r->vendor_name,
                'status'   => $r->status,
                'amount'   => $r->total_amount !== null ? (string) $r->total_amount : null,
                'url'      => '/purchasing/purchase-orders/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Work orders -----------------------------------------------------------
        if ($user->can('production.work_orders.view') && Schema::hasTable('work_orders')) {
            $rows = DB::table('work_orders as wo')
                ->leftJoin('products as p', 'p.id', '=', 'wo.product_id')
                ->leftJoin('machines as m', 'm.id', '=', 'wo.machine_id')
                ->select('wo.id', 'wo.wo_number', 'wo.status', 'p.name as product_name', 'p.part_number', 'm.name as machine_name')
                ->where(fn ($q) => $q
                    ->where('wo.wo_number', $like, $term)
                    ->orWhere('p.name', $like, $term)
                    ->orWhere('p.part_number', $like, $term)
                    ->orWhere('m.name', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Work Orders', 'work_order', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->wo_number,
                'sublabel' => trim((string) ($r->product_name ?? '').($r->machine_name ? ' · '.$r->machine_name : '')),
                'status'   => $r->status,
                'url'      => '/production/work-orders/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Invoices --------------------------------------------------------------
        if ($user->can('accounting.invoices.view') && Schema::hasTable('invoices')) {
            $rows = DB::table('invoices as i')
                ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
                ->select('i.id', 'i.invoice_number', 'i.status', 'i.total_amount', 'c.name as customer_name')
                ->where(fn ($q) => $q
                    ->where('i.invoice_number', $like, $term)
                    ->orWhere('c.name', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Invoices', 'invoice', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->invoice_number,
                'sublabel' => $r->customer_name,
                'status'   => $r->status,
                'amount'   => $r->total_amount !== null ? (string) $r->total_amount : null,
                'url'      => '/accounting/invoices/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Bills -----------------------------------------------------------------
        if ($user->can('accounting.bills.view') && Schema::hasTable('bills')) {
            $rows = DB::table('bills as b')
                ->leftJoin('vendors as v', 'v.id', '=', 'b.vendor_id')
                ->select('b.id', 'b.bill_number', 'b.status', 'b.total_amount', 'v.name as vendor_name')
                ->where(fn ($q) => $q
                    ->where('b.bill_number', $like, $term)
                    ->orWhere('v.name', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Bills', 'bill', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->bill_number,
                'sublabel' => $r->vendor_name,
                'status'   => $r->status,
                'amount'   => $r->total_amount !== null ? (string) $r->total_amount : null,
                'url'      => '/accounting/bills/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Products --------------------------------------------------------------
        if ($user->can('crm.products.view') && Schema::hasTable('products')) {
            $rows = DB::table('products')
                ->select('id', 'part_number', 'name')
                ->where(fn ($q) => $q
                    ->where('part_number', $like, $term)
                    ->orWhere('name', $like, $term))
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
        if ($user->can('inventory.items.view') && Schema::hasTable('items')) {
            $rows = DB::table('items')
                ->select('id', 'code', 'name', 'item_type')
                ->where(fn ($q) => $q
                    ->where('code', $like, $term)
                    ->orWhere('name', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Items', 'item', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => trim((string) ($r->code ?? '').($r->item_type ? ' · '.$r->item_type : '')),
                'status'   => null,
                'url'      => '/inventory/items/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Customers -------------------------------------------------------------
        if ($user->can('accounting.customers.view') && Schema::hasTable('customers')) {
            $rows = DB::table('customers')
                ->select('id', 'name', 'tin', 'contact_person')
                ->where(fn ($q) => $q
                    ->where('name', $like, $term)
                    ->orWhere('contact_person', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Customers', 'customer', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->contact_person ?: $r->tin,
                'status'   => null,
                'url'      => '/accounting/customers/'.$h->encode((int) $r->id),
            ])->all());
        }

        // Vendors ---------------------------------------------------------------
        if ($user->can('accounting.vendors.view') && Schema::hasTable('vendors')) {
            $rows = DB::table('vendors')
                ->select('id', 'name', 'tin', 'contact_person')
                ->where(fn ($q) => $q
                    ->where('name', $like, $term)
                    ->orWhere('contact_person', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('Vendors', 'vendor', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->name,
                'sublabel' => $r->contact_person ?: $r->tin,
                'status'   => null,
                'url'      => '/accounting/vendors/'.$h->encode((int) $r->id),
            ])->all());
        }

        // NCRs ------------------------------------------------------------------
        if ($user->can('quality.ncr.view') && Schema::hasTable('non_conformance_reports')) {
            $rows = DB::table('non_conformance_reports')
                ->select('id', 'ncr_number', 'status', 'severity', 'defect_description')
                ->where(fn ($q) => $q
                    ->where('ncr_number', $like, $term)
                    ->orWhere('defect_description', $like, $term))
                ->limit($perGroup)->get();
            $groups[] = $this->wrap('NCRs', 'ncr', $rows->map(fn ($r) => [
                'id'       => $h->encode((int) $r->id),
                'label'    => $r->ncr_number,
                'sublabel' => $r->severity ? 'Severity: '.$r->severity : null,
                'status'   => $r->status,
                'url'      => '/quality/ncrs/'.$h->encode((int) $r->id),
            ])->all());
        }

        return array_values(array_filter($groups, fn ($g) => count($g['items']) > 0));
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
