<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Quality\Requests\CreateInspectionRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class InspectionCreateSourceRequiredTest extends TestCase
{
    public function test_source_record_is_required_to_create_an_inspection(): void
    {
        $rules = (new CreateInspectionRequest)->rules();

        $validator = Validator::make([
            'stage' => 'in_process',
            'product_id' => 1,
            'batch_quantity' => 10,
        ], [
            'entity_type' => $rules['entity_type'],
            'entity_id' => $rules['entity_id'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('entity_type', $validator->errors()->toArray());
        $this->assertArrayHasKey('entity_id', $validator->errors()->toArray());
    }
}
