<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Contracts\DetectorEmission;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DetectorEmissionTest extends TestCase
{
    public function test_existing_constructor_arguments_remain_compatible(): void
    {
        $emission = new DetectorEmission('test', 'info', 'key', ['rule' => 'test']);

        $this->assertNull($emission->decisionCode);
        $this->assertNull($emission->sourceReferences);
    }

    public function test_source_references_reject_raw_ids_php_classes_and_oversized_lists(): void
    {
        $reference = [
            'source_kind' => 'work_order',
            'public_id' => 'abc123',
            'display_reference' => 'WO-42',
            'destination' => '/production/work-orders/abc123',
        ];

        foreach ([
            [...$reference, 'unused' => 'value'],
            [...$reference, 'public_id' => '42'],
            [...$reference, 'source_kind' => 'App\\Modules\\Production\\Models\\WorkOrder'],
            [...$reference, 'destination' => 'https://example.test/work-orders/abc123'],
        ] as $invalid) {
            try {
                new DetectorEmission('test', 'info', 'key', [], sourceReferences: [$invalid]);
                $this->fail('Expected invalid source reference rejection.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        try {
            new DetectorEmission('test', 'info', 'key', [], sourceReferences: array_fill(0, 6, $reference));
            $this->fail('Expected source reference limit rejection.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }
}
