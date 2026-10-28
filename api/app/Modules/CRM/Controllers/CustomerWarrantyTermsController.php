<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\CustomerProductWarrantyOverride;
use App\Modules\CRM\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CustomerWarrantyTermsController
{
    public function index(Customer $customer): JsonResponse
    {
        $terms = CustomerProductWarrantyOverride::query()
            ->with(['product' => fn ($query) => $query->withTrashed()->select('id', 'part_number', 'name', 'unit_of_measure')])
            ->where('customer_id', $customer->id)
            ->orderBy('product_id')
            ->get()
            ->map(fn (CustomerProductWarrantyOverride $term): array => $this->serialize($term));

        return response()->json(['data' => $terms]);
    }

    public function upsert(Request $request, Customer $customer, Product $product): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'warranty_duration_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'warranty_report_window_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'warranty_start_basis' => ['sometimes', 'nullable', Rule::in(['delivery', 'invoice'])],
            'warranty_covered_issues' => ['sometimes', 'nullable', 'array'],
            'warranty_covered_issues.*' => ['string', 'distinct', Rule::in(['manufacturing_defect', 'shipping_damage', 'customer_caused_damage'])],
            'warranty_remedies' => ['sometimes', 'nullable', 'array'],
            'warranty_remedies.*' => ['string', 'distinct', Rule::in(['repair', 'replace', 'credit', 'refund'])],
        ])->validate();

        $term = CustomerProductWarrantyOverride::query()->updateOrCreate(
            ['customer_id' => $customer->id, 'product_id' => $product->id],
            $validated,
        )->load(['product' => fn ($query) => $query->withTrashed()->select('id', 'part_number', 'name', 'unit_of_measure')]);

        return response()->json(['data' => $this->serialize($term)]);
    }

    public function destroy(Customer $customer, Product $product): JsonResponse
    {
        CustomerProductWarrantyOverride::query()
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->delete();

        return response()->json(null, 204);
    }

    private function serialize(CustomerProductWarrantyOverride $term): array
    {
        return [
            'id' => $term->hash_id,
            'product' => [
                'id' => $term->product?->hash_id,
                'part_number' => $term->product?->part_number,
                'name' => $term->product?->name,
                'unit_of_measure' => $term->product?->unit_of_measure,
            ],
            'warranty_duration_days' => $term->warranty_duration_days,
            'warranty_report_window_days' => $term->warranty_report_window_days,
            'warranty_start_basis' => $term->warranty_start_basis,
            'warranty_covered_issues' => $term->warranty_covered_issues,
            'warranty_remedies' => $term->warranty_remedies,
        ];
    }
}
