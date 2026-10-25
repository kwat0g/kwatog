<?php

declare(strict_types=1);

namespace App\Modules\DSS\Support;

use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Shared D27 source rules for detection, affected-record lists, and verification. */
final class MasterHygieneScope
{
    /** @return array<string, array{permission: string, why: string, route: string, evidence_column: string, requirement: string}> */
    public function definitions(): array
    {
        return [
            'items_missing_reorder_point' => [
                'permission' => 'inventory.items.manage',
                'why' => 'Items without a usable reorder point are invisible to auto-replenishment.',
                'route' => '/inventory/items',
                'evidence_column' => 'code',
                'requirement' => 'Usable reorder point',
            ],
            'items_missing_safety_stock' => [
                'permission' => 'inventory.items.manage',
                'why' => 'Items without safety stock lose their MRP buffer.',
                'route' => '/inventory/items',
                'evidence_column' => 'code',
                'requirement' => 'Safety stock',
            ],
            'items_missing_lead_time' => [
                'permission' => 'inventory.items.manage',
                'why' => 'Items without lead time fall back to the global default in planning.',
                'route' => '/inventory/items',
                'evidence_column' => 'code',
                'requirement' => 'Lead time',
            ],
            'items_missing_standard_cost' => [
                'permission' => 'inventory.items.manage',
                'why' => 'Items without standard cost block auto-PR creation and distort margins.',
                'route' => '/inventory/items',
                'evidence_column' => 'code',
                'requirement' => 'Standard cost',
            ],
            'items_without_approved_supplier' => [
                'permission' => 'purchasing.po.create',
                'why' => 'Items with no approved supplier cannot be auto-sourced.',
                'route' => '/purchasing/approved-suppliers',
                'evidence_column' => 'code',
                'requirement' => 'Approved supplier',
            ],
            'customers_missing_credit_limit' => [
                'permission' => 'accounting.customers.manage',
                'why' => 'Customers without credit limits cannot be checked at order acceptance (D12).',
                'route' => '/crm/customers',
                'evidence_column' => 'name',
                'requirement' => 'Credit limit',
            ],
            'products_without_active_bom' => [
                'permission' => 'mrp.boms.manage',
                'why' => 'Products without an active BOM cannot be MRP-planned.',
                'route' => '/mrp/boms',
                'evidence_column' => 'part_number',
                'requirement' => 'Active bill of materials',
            ],
        ];
    }

    /** @return Builder<Model> */
    public function query(string $gap): Builder
    {
        return match ($gap) {
            'items_missing_reorder_point' => Item::query()->where('is_active', true)
                ->where(fn (Builder $query) => $query->whereNull('reorder_point')->orWhere('reorder_point', '<=', 0)),
            'items_missing_safety_stock' => Item::query()->where('is_active', true)->whereNull('safety_stock'),
            'items_missing_lead_time' => Item::query()->where('is_active', true)
                ->where(fn (Builder $query) => $query->whereNull('lead_time_days')->orWhere('lead_time_days', '<=', 0)),
            'items_missing_standard_cost' => Item::query()->where('is_active', true)
                ->where(fn (Builder $query) => $query->whereNull('standard_cost')->orWhere('standard_cost', '<=', 0)),
            'items_without_approved_supplier' => Item::query()->where('is_active', true)
                ->whereNotExists(fn ($exists) => $exists->select(DB::raw(1))->from('approved_suppliers')
                    ->whereColumn('approved_suppliers.item_id', 'items.id')),
            'customers_missing_credit_limit' => Customer::query()->whereNull('credit_limit'),
            'products_without_active_bom' => Product::query()->where('is_active', true)
                ->whereNotExists(fn ($exists) => $exists->select(DB::raw(1))->from('bill_of_materials')
                    ->whereColumn('bill_of_materials.product_id', 'products.id')
                    ->where('bill_of_materials.is_active', true)),
            default => throw new InvalidArgumentException("Unknown master-data gap '{$gap}'."),
        };
    }

    public function applySearch(Builder $query, string $gap, string $search): void
    {
        $columns = match ($gap) {
            'items_missing_reorder_point', 'items_missing_safety_stock', 'items_missing_lead_time',
            'items_missing_standard_cost', 'items_without_approved_supplier' => ['code', 'name'],
            'customers_missing_credit_limit' => ['code', 'name'],
            'products_without_active_bom' => ['part_number', 'name'],
            default => throw new InvalidArgumentException("Unknown master-data gap '{$gap}'."),
        };
        $pattern = SearchOperator::contains($search);
        $query->where(function (Builder $builder) use ($columns, $pattern): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $builder->where($column, SearchOperator::like(), $pattern);
                } else {
                    $builder->orWhere($column, SearchOperator::like(), $pattern);
                }
            }
        });
    }

    /** @return array{reference: string, name: string, requirement: string, destination: string} */
    public function present(string $gap, Model $record): array
    {
        $hash = $record->hash_id;
        $definition = $this->definitions()[$gap] ?? throw new InvalidArgumentException("Unknown master-data gap '{$gap}'.");
        $isItem = str_starts_with($gap, 'items_');
        $destination = match (true) {
            $gap === 'items_without_approved_supplier' => '/purchasing/approved-suppliers?item_id='.urlencode($hash),
            $isItem => "/inventory/items/{$hash}/edit",
            $gap === 'customers_missing_credit_limit' => "/crm/customers/{$hash}/edit",
            $gap === 'products_without_active_bom' => '/mrp/boms/create?product_id='.urlencode($hash),
            default => throw new InvalidArgumentException("Unknown master-data gap '{$gap}'."),
        };

        return [
            'reference' => (string) match (true) {
                $record instanceof Item => $record->code,
                $record instanceof Customer => $record->code ?: $record->name,
                $record instanceof Product => $record->part_number,
                default => throw new InvalidArgumentException('Unexpected master-data record.'),
            },
            'name' => (string) match (true) {
                $record instanceof Item, $record instanceof Product => $record->name,
                $record instanceof Customer => $record->name,
                default => throw new InvalidArgumentException('Unexpected master-data record.'),
            },
            'requirement' => $definition['requirement'],
            'destination' => $destination,
        ];
    }
}
