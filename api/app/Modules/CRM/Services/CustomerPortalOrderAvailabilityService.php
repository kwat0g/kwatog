<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Shares the ERP's production/QC/reservation aware ATP result with customer ordering.
 * Products with no inventory mapping remain build-to-order; mapped products can
 * only be ordered against the same QC released, unreserved stock used by dispatch.
 */
final class CustomerPortalOrderAvailabilityService
{
    private ?bool $orderLimitColumnsSupported = null;

    public function __construct(private readonly SalesOrderSupplyService $supply) {}

    /** @return Collection<int, array{product: Product, available_quantity: ?string, availability_mode: string, warranty_options: Collection<int, CustomerProductWarrantyOption>}> */
    public function forCustomer(Customer $customer, ?string $search = null): Collection
    {
        $query = Product::query()->active();
        if ($customer->portal_catalog_mode === 'standard') {
            $query->where(fn ($products) => $products->where('portal_visibility', 'standard')
                ->orWhereHas('portalCustomers', fn ($customers) => $customers->whereKey($customer->id)));
        } else {
            $query->whereHas('portalCustomers', fn ($customers) => $customers->whereKey($customer->id));
        }
        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(fn ($match) => $match->where('part_number', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        return $query->with(['images', 'blueprints'])->orderBy('part_number')->get()
            ->map(fn (Product $product): array => [
                'product' => $product,
                ...$this->forProduct($customer, $product),
            ]);
    }

    /** @return array{available_quantity: ?string, availability_mode: string, warranty_options: Collection<int, CustomerProductWarrantyOption>, minimum_order_quantity: string, maximum_order_quantity: ?string, order_limits_supported: bool} */
    public function forProduct(Customer $customer, Product $product, bool $lock = false, ?int $excludeSalesOrderId = null): array
    {
        $item = Item::withTrashed()->where('code', $product->part_number)->first();
        $mapped = $item !== null;
        $usableMapping = $mapped && ! $item->trashed() && $item->is_active
            && $item->item_type === ItemType::FinishedGood
            && strcasecmp(trim((string) $item->unit_of_measure), trim((string) $product->unit_of_measure)) === 0;

        $available = null;
        if ($mapped) {
            $available = '0.000';
            if ($usableMapping && $product->is_active && ! $product->trashed()) {
                $line = new SalesOrderItem([
                    'product_id' => $product->id,
                    'quantity' => '1.00',
                    'fulfillment_policy' => 'stock_first',
                ]);
                $line->setRelation('product', $product);
                foreach ($this->supply->sources($line, $lock) as $source) {
                    $available = bcadd($available, (string) $source['quantity'], 3);
                }
                $committed = $this->outstandingStockDemand((int) $product->id, $lock, $excludeSalesOrderId);
                $available = bcsub($available, $committed, 3);
                if (bccomp($available, '0.000', 3) < 0) {
                    $available = '0.000';
                }
            }
        }

        $options = CustomerProductWarrantyOption::query()
            ->where('customer_id', $customer->id)->where('product_id', $product->id)
            ->orderBy('position')->orderBy('id')->get();

        $orderLimitsSupported = $this->orderLimitColumnsSupported ??= Schema::hasColumns('customer_product_access', [
            'minimum_order_quantity', 'maximum_order_quantity',
        ]);
        $access = $orderLimitsSupported
            ? DB::table('customer_product_access')->where('customer_id', $customer->id)->where('product_id', $product->id)->first()
            : null;

        return [
            'available_quantity' => $available,
            'availability_mode' => $mapped ? 'stock_limited' : 'make_to_order',
            'warranty_options' => $options,
            'minimum_order_quantity' => (string) ($access?->minimum_order_quantity ?? '1.000'),
            'maximum_order_quantity' => $access?->maximum_order_quantity,
            'order_limits_supported' => $orderLimitsSupported,
        ];
    }

    /** @param list<array{product_id: int, quantity: string}> $items */
    public function assertOrderAvailability(Customer $customer, array $items, ?int $excludeSalesOrderId = null): void
    {
        if (DB::transactionLevel() === 0) {
            DB::transaction(fn () => $this->assertOrderAvailability($customer, $items, $excludeSalesOrderId), 3);

            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
        $productIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['product_id'], $items)));
        sort($productIds);
        $products = Product::query()->active()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $requestedByProduct = [];
        $firstLineByProduct = [];
        $limitsByProduct = [];
        foreach ($items as $index => $line) {
            $product = $products->get((int) $line['product_id']);
            if (! $product || ! $product->isAvailableToCustomer((int) $customer->id)) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => ['This product is not included in your customer agreement. Contact Sales.']]);
            }

            $availability = $this->forProduct($customer, $product, lock: true, excludeSalesOrderId: $excludeSalesOrderId);
            $available = $availability['available_quantity'];
            $productId = (int) $product->id;
            $requestedByProduct[$productId] = bcadd($requestedByProduct[$productId] ?? '0.000', (string) $line['quantity'], 3);
            $firstLineByProduct[$productId] ??= $index;
            $limitsByProduct[$productId] = $availability;
            if ($available !== null && bccomp($requestedByProduct[$productId], $available, 3) > 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ["Only {$available} units are currently available for this stock item. Request a price and availability review for a larger quantity."],
                ]);
            }
        }

        foreach ($requestedByProduct as $productId => $quantity) {
            $limits = $limitsByProduct[$productId];
            if (bccomp($quantity, $limits['minimum_order_quantity'], 3) < 0) {
                throw ValidationException::withMessages([
                    'items.'.$firstLineByProduct[$productId].'.quantity' => ["Order at least {$limits['minimum_order_quantity']} units of this product."],
                ]);
            }
            if ($limits['maximum_order_quantity'] !== null
                && bccomp($quantity, $limits['maximum_order_quantity'], 3) > 0) {
                throw ValidationException::withMessages([
                    'items.'.$firstLineByProduct[$productId].'.quantity' => ["Order no more than {$limits['maximum_order_quantity']} units of this product per sales order."],
                ]);
            }
        }
    }

    private function outstandingStockDemand(int $productId, bool $lock, ?int $excludeSalesOrderId): string
    {
        $lines = SalesOrderItem::query()
            ->where('product_id', $productId)
            ->where('fulfillment_policy', 'stock_first')
            ->whereHas('salesOrder', fn ($orders) => $orders->whereNotIn('status', [
                SalesOrderStatus::Draft->value,
                SalesOrderStatus::Closed->value,
                SalesOrderStatus::Cancelled->value,
            ]))
            ->when($excludeSalesOrderId !== null, fn ($query) => $query->where('sales_order_id', '!=', $excludeSalesOrderId))
            ->orderBy('sales_order_id')->orderBy('id');
        if ($lock) {
            $lines->lockForUpdate();
        }

        return $lines->get()->reduce(function (string $total, SalesOrderItem $line): string {
            $uncovered = $this->supply->lineCoverage($line)['uncovered'];

            return bcadd($total, (string) $uncovered, 3);
        }, '0.000');
    }
}
