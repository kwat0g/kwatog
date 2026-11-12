<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Accounting\Requests\StoreCustomerRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

final class CustomerWarrantyRemedyScopeTest extends TestCase
{
    public function test_a_remedy_can_be_used_by_multiple_product_warranty_choices(): void
    {
        $requestRules = (new StoreCustomerRequest())->rules();
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make([
            'product_warranties' => [
                ['warranty_remedies' => ['repair', 'replace']],
                ['warranty_remedies' => ['replace']],
            ],
        ], [
            'product_warranties' => ['required', 'array', 'list'],
            'product_warranties.*.warranty_remedies' => ['nullable', 'array', 'list'],
            'product_warranties.*.warranty_remedies.*' => $requestRules['product_warranties.*.warranty_remedies.*'],
        ]);

        self::assertTrue($validator->passes(), $validator->errors()->toJson());
    }
}
