<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\CustomerProductWarrantyOverride;
use App\Modules\CRM\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerWarrantyTermsController
{
    public function index(Customer $customer): JsonResponse
    {
        $terms = CustomerProductWarrantyOption::query()
            ->with(['product' => fn ($query) => $query->withTrashed()->select('id', 'part_number', 'name', 'unit_of_measure')])
            ->where('customer_id', $customer->id)
            ->orderBy('product_id')->orderBy('position')->orderBy('id')
            ->get()
            ->map(fn (CustomerProductWarrantyOption $term): array => $this->serializeOption($term));

        return response()->json(['data' => $terms]);
    }

    public function storeOption(Request $request, Customer $customer, Product $product): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'label' => ['required', 'string', 'max:120', Rule::unique('customer_product_warranty_options', 'label')
                ->where('customer_id', $customer->id)->where('product_id', $product->id)],
            'warranty_duration_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'warranty_report_window_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'warranty_start_basis' => ['nullable', Rule::in(['delivery', 'invoice'])],
            'warranty_covered_issues' => ['nullable', 'array'],
            'warranty_covered_issues.*' => ['string', 'distinct', Rule::in(['manufacturing_defect', 'shipping_damage', 'customer_caused_damage'])],
            'warranty_remedies' => ['nullable', 'array'],
            'warranty_remedies.*' => ['string', 'distinct', Rule::in(['repair', 'replace', 'credit', 'refund'])],
            'additional_terms' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $option = DB::transaction(function () use ($customer, $product, $validated): CustomerProductWarrantyOption {
            Customer::query()->lockForUpdate()->findOrFail($customer->id);
            Product::query()->lockForUpdate()->findOrFail($product->id);
            if (CustomerProductWarrantyOption::query()
                ->where('customer_id', $customer->id)->where('product_id', $product->id)
                ->where('label', $validated['label'])->exists()) {
                throw ValidationException::withMessages([
                    'label' => ['A warranty choice with this name already exists for this product.'],
                ]);
            }
            $position = (int) CustomerProductWarrantyOption::query()
                ->where('customer_id', $customer->id)->where('product_id', $product->id)
                ->max('position') + 1;

            return CustomerProductWarrantyOption::query()->create([
                ...$validated,
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'position' => $position,
            ]);
        });
        $option->load(['product' => fn ($query) => $query->withTrashed()->select('id', 'part_number', 'name', 'unit_of_measure')]);

        return response()->json(['data' => $this->serializeOption($option)], 201);
    }

    public function destroyOption(Customer $customer, Product $product, CustomerProductWarrantyOption $option): JsonResponse
    {
        abort_unless((int) $option->customer_id === (int) $customer->id
            && (int) $option->product_id === (int) $product->id, 404);
        DB::transaction(function () use ($option, $customer, $product): void {
            CustomerProductWarrantyOption::query()->whereKey($option->id)->lockForUpdate()->firstOrFail()->delete();
            if (! CustomerProductWarrantyOption::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->exists()) {
                CustomerProductWarrantyOverride::query()->where('customer_id', $customer->id)->where('product_id', $product->id)->get()
                    ->each(fn (CustomerProductWarrantyOverride $legacy) => $legacy->delete());
            }
        });

        return response()->json(null, 204);
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

        $term = DB::transaction(function () use ($customer, $product, $validated): CustomerProductWarrantyOverride {
            $term = CustomerProductWarrantyOverride::query()->updateOrCreate(
                ['customer_id' => $customer->id, 'product_id' => $product->id],
                $validated,
            );
            $option = CustomerProductWarrantyOption::query()
                ->where('customer_id', $customer->id)->where('product_id', $product->id)
                ->orderBy('position')->orderBy('id')->lockForUpdate()->first();
            $optionData = [
                'warranty_duration_days' => $validated['warranty_duration_days'] ?? null,
                'warranty_report_window_days' => $validated['warranty_report_window_days'] ?? null,
                'warranty_start_basis' => $validated['warranty_start_basis'] ?? null,
                'warranty_covered_issues' => $validated['warranty_covered_issues'] ?? null,
                'warranty_remedies' => $validated['warranty_remedies'] ?? null,
            ];
            CustomerProductWarrantyOption::withoutEvents(function () use ($option, $optionData, $customer, $product): void {
                if ($option) {
                    $option->update($optionData);
                } elseif (collect($optionData)->contains(static fn (mixed $value): bool => $value !== null)) {
                    $duration = $optionData['warranty_duration_days'];
                    CustomerProductWarrantyOption::query()->create([
                        ...$optionData,
                        'customer_id' => $customer->id,
                        'product_id' => $product->id,
                        'label' => $duration === null ? 'Default warranty terms' : "{$duration}-day warranty",
                        'position' => 1,
                    ]);
                }
            });

            return $term;
        })->load(['product' => fn ($query) => $query->withTrashed()->select('id', 'part_number', 'name', 'unit_of_measure')]);

        return response()->json(['data' => $this->serialize($term)]);
    }

    public function destroy(Customer $customer, Product $product): JsonResponse
    {
        DB::transaction(function () use ($customer, $product): void {
            CustomerProductWarrantyOverride::query()
                ->where('customer_id', $customer->id)
                ->where('product_id', $product->id)
                ->get()
                ->each(fn (CustomerProductWarrantyOverride $term) => $term->delete());
            CustomerProductWarrantyOption::query()
                ->where('customer_id', $customer->id)
                ->where('product_id', $product->id)
                ->get()
                ->each(fn (CustomerProductWarrantyOption $option) => $option->delete());
        });

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

    private function serializeOption(CustomerProductWarrantyOption $option): array
    {
        return [
            'id' => $option->hash_id,
            'product' => [
                'id' => $option->product?->hash_id,
                'part_number' => $option->product?->part_number,
                'name' => $option->product?->name,
                'unit_of_measure' => $option->product?->unit_of_measure,
            ],
            'label' => $option->label,
            'warranty_duration_days' => $option->warranty_duration_days,
            'warranty_report_window_days' => $option->warranty_report_window_days,
            'warranty_start_basis' => $option->warranty_start_basis,
            'warranty_covered_issues' => $option->warranty_covered_issues,
            'warranty_remedies' => $option->warranty_remedies,
            'additional_terms' => $option->additional_terms,
        ];
    }
}
