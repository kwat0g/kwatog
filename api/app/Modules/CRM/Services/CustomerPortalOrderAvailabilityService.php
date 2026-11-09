<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shares the ERP's production/QC/reservation aware ATP result with customer ordering.
 * Products with no inventory mapping remain build-to-order; mapped products can
 * only be ordered against the same QC released, unreserved stock used by dispatch.
 */
final class CustomerPortalOrderAvailabilityService
{
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

    /** @return array{available_quantity: ?string, availability_mode: string, warranty_options: Collection<int, CustomerProductWarrantyOption>} */
    public function forProduct(Customer $customer, Product $product, bool $lock = false): array
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
            }
        }

        $options = CustomerProductWarrantyOption::query()
            ->where('customer_id', $customer->id)->where('product_id', $product->id)
            ->orderBy('position')->orderBy('id')->get();

        return [
            'available_quantity' => $available,
            'availability_mode' => $mapped ? 'stock_limited' : 'make_to_order',
            'warranty_options' => $options,
        ];
    }

    /** @param list<array{product_id: int, quantity: string}> $items */
    public function assertOrderAvailability(Customer $customer, array $items): void
    {
        if (DB::transactionLevel() === 0) {
            DB::transaction(fn () => $this->assertOrderAvailability($customer, $items), 3);

            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
        $productIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['product_id'], $items)));
        sort($productIds);
        $products = Product::query()->active()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($items as $index => $line) {
            $product = $products->get((int) $line['product_id']);
            if (! $product || ! $product->isAvailableToCustomer((int) $customer->id)) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => ['This product is not included in your customer agreement. Contact Sales.']]);
            }

            $availability = $this->forProduct($customer, $product, lock: true);
            $available = $availability['available_quantity'];
            if ($available !== null && bccomp((string) $line['quantity'], $available, 3) > 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ["Only {$available} units are currently available for this stock item. Request a price and availability review for a larger quantity."],
                ]);
            }
        }
    }
}
