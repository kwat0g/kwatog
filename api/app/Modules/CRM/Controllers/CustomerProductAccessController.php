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

final class CustomerProductAccessController
{
    public function index(Customer $customer): JsonResponse
    {
        $selected = $customer->portalProducts()->pluck('products.id')->map(static fn ($id): int => (int) $id)->all();
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
            ])->values();

        return response()->json(['data' => [
            'portal_catalog_mode' => $customer->portal_catalog_mode,
            'product_ids' => Product::query()->whereIn('id', $selected)->orderBy('part_number')->get()->map(fn (Product $product): string => $product->hash_id)->values(),
            'products' => $products,
        ]]);
    }

    public function update(UpdateCustomerProductAccessRequest $request, Customer $customer): JsonResponse
    {
        $data = $request->validated();
        $productIds = array_values(array_unique(array_map('intval', $data['product_ids'])));
        sort($productIds);

        DB::transaction(function () use ($customer, $data, $productIds): void {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $changed = $lockedCustomer->portalProducts()->orderBy('products.id')->pluck('products.id')
                ->map(static fn ($id): int => (int) $id)->all();
            sort($changed);
            $lockedCustomer->portalProducts()->sync($productIds);
            $modeChanged = $lockedCustomer->portal_catalog_mode !== $data['portal_catalog_mode'];
            $lockedCustomer->update(['portal_catalog_mode' => $data['portal_catalog_mode']]);

            foreach (array_values(array_unique(array_merge($changed, $productIds))) as $productId) {
                $product = Product::query()->find($productId);
                if ($product) {
                    app(ProductRealtimeService::class)->stage($product, 'updated');
                }
            }
            if ($changed !== $productIds || $modeChanged) {
                app(CustomerRealtimeService::class)->stage($lockedCustomer, 'updated', includeCustomerProfile: false);
            }
        });

        return $this->index($customer->fresh());
    }
}
