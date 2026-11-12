<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Services\CustomerRealtimeService;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Requests\UpdateCustomerProductAccessRequest;
use App\Modules\CRM\Services\ProductRealtimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class CustomerProductAccessController
{
    public function orderLimitCapability(): JsonResponse
    {
        return response()->json(['data' => [
            'supported' => Schema::hasColumns('customer_product_access', [
                'minimum_order_quantity', 'maximum_order_quantity',
            ]),
        ]]);
    }

    public function index(Customer $customer): JsonResponse
    {
        $selected = $customer->portalProducts()->pluck('products.id')->map(static fn ($id): int => (int) $id)->all();
        $supportsOrderLimits = Schema::hasColumns('customer_product_access', [
            'minimum_order_quantity', 'maximum_order_quantity',
        ]);
        $limitsByProduct = $supportsOrderLimits
            ? DB::table('customer_product_access')->where('customer_id', $customer->id)->whereIn('product_id', $selected)->get()->keyBy('product_id')
            : collect();
        $products = Product::query()->active()->with('images')->orderBy('part_number')->get()
            ->map(fn (Product $product): array => [
                'id' => $product->hash_id,
                'part_number' => $product->part_number,
                'name' => $product->name,
                'unit_of_measure' => $product->unit_of_measure,
                'image_url' => $product->image_path ? "/api/v1/crm/products/{$product->hash_id}/image" : null,
                'portal_visibility' => $product->portal_visibility,
                'is_explicitly_authorized' => in_array((int) $product->id, $selected, true),
                'is_authorized' => in_array((int) $product->id, $selected, true)
                    || ($customer->portal_catalog_mode === 'standard' && $product->portal_visibility === 'standard'),
                'minimum_order_quantity' => $supportsOrderLimits
                    ? (string) ($limitsByProduct->get($product->id)?->minimum_order_quantity ?? '1.000')
                    : '1.000',
                'maximum_order_quantity' => $supportsOrderLimits
                    ? $limitsByProduct->get($product->id)?->maximum_order_quantity
                    : null,
            ])->values();

        return response()->json(['data' => [
            'portal_catalog_mode' => $customer->portal_catalog_mode,
            'order_limits_supported' => $supportsOrderLimits,
            'product_ids' => Product::query()->whereIn('id', $selected)->orderBy('part_number')->get()->map(fn (Product $product): string => $product->hash_id)->values(),
            'products' => $products,
        ]]);
    }

    public function update(UpdateCustomerProductAccessRequest $request, Customer $customer): JsonResponse
    {
        $data = $request->validated();
        $productIds = array_values(array_unique(array_map('intval', $data['product_ids'])));
        sort($productIds);
        $supportsOrderLimits = Schema::hasColumns('customer_product_access', [
            'minimum_order_quantity', 'maximum_order_quantity',
        ]);
        $submittedLimits = $data['product_order_limits'] ?? [];
        if (! $supportsOrderLimits && collect($submittedLimits)->contains(static fn (array $limit): bool =>
            (int) ($limit['minimum_order_quantity'] ?? 1) !== 1
            || ($limit['maximum_order_quantity'] ?? null) !== null)) {
            throw ValidationException::withMessages([
                'product_order_limits' => ['Customer quantity limits need a database update before they can be saved.'],
            ]);
        }

        DB::transaction(function () use ($customer, $data, $productIds, $supportsOrderLimits, $submittedLimits): void {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $changed = $lockedCustomer->portalProducts()->orderBy('products.id')->pluck('products.id')
                ->map(static fn ($id): int => (int) $id)->all();
            sort($changed);
            $limitsChanged = false;
            if ($supportsOrderLimits) {
                $currentLimits = DB::table('customer_product_access')->where('customer_id', $lockedCustomer->id)
                    ->whereIn('product_id', $productIds)->get()->keyBy('product_id');
                $submittedByProduct = collect($submittedLimits)->keyBy(static fn (array $limit): int => (int) $limit['product_id']);
                $syncData = [];
                foreach ($productIds as $productId) {
                    $current = $currentLimits->get($productId);
                    $submitted = $submittedByProduct->get($productId);
                    $minimum = $submitted['minimum_order_quantity'] ?? $current?->minimum_order_quantity ?? 1;
                    $maximum = array_key_exists('maximum_order_quantity', $submitted ?? [])
                        ? $submitted['maximum_order_quantity']
                        : ($current?->maximum_order_quantity);
                    $syncData[$productId] = [
                        'minimum_order_quantity' => $minimum,
                        'maximum_order_quantity' => $maximum,
                    ];
                    if ((int) $minimum !== (int) ($current?->minimum_order_quantity ?? 1)
                        || ($maximum === null ? null : (int) $maximum) !== ($current?->maximum_order_quantity === null ? null : (int) $current?->maximum_order_quantity)) {
                        $limitsChanged = true;
                    }
                }
                $lockedCustomer->portalProducts()->sync($syncData);
            } else {
                $lockedCustomer->portalProducts()->sync($productIds);
            }
            $modeChanged = $lockedCustomer->portal_catalog_mode !== $data['portal_catalog_mode'];
            $lockedCustomer->update(['portal_catalog_mode' => $data['portal_catalog_mode']]);

            foreach (array_values(array_unique(array_merge($changed, $productIds))) as $productId) {
                $product = Product::query()->find($productId);
                if ($product) {
                    app(ProductRealtimeService::class)->stage($product, 'updated');
                }
            }
            if ($changed !== $productIds || $modeChanged || $limitsChanged) {
                app(CustomerRealtimeService::class)->stage($lockedCustomer, 'updated', includeCustomerProfile: false);
            }
        });

        return $this->index($customer->fresh());
    }
}
