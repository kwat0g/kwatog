<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CRM\Services\CustomerAgreementSampleGenerator;
use PHPUnit\Framework\TestCase;

class CustomerAgreementSampleGeneratorTest extends TestCase
{
    public function test_each_sample_has_a_fresh_fictional_customer_profile_and_keeps_catalog_part_numbers(): void
    {
        $generator = new CustomerAgreementSampleGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        foreach (['customer_name', 'contact_person', 'contact_phone', 'contact_email', 'billing_address', 'delivery_address', 'reference'] as $field) {
            $this->assertNotSame('', $first[$field]);
            $this->assertNotSame($first[$field], $second[$field], "Expected {$field} to differ between downloads.");
        }

        $this->assertStringContainsString(' Demo ', $first['customer_name']);
        $this->assertStringEndsWith('.test', $first['contact_email']);
        $this->assertSame(['WB-001', 'PC-001'], array_column($first['products'], 'part_number'));
        $this->assertSame(['WB-001', 'PC-001'], array_column($second['products'], 'part_number'));
        $this->assertSame([5, 1], array_column($first['products'], 'minimum_order_quantity'));
        $this->assertSame([250, 80], array_column($first['products'], 'maximum_order_quantity'));
    }
}
