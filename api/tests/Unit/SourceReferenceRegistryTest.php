<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Services\SourceReferenceRegistry;
use PHPUnit\Framework\TestCase;

final class SourceReferenceRegistryTest extends TestCase
{
    public function test_only_inventory_and_accounting_source_types_are_allow_listed(): void
    {
        self::assertArrayHasKey('bill', SourceReferenceRegistry::types());
        self::assertArrayHasKey('stock_adjustment', SourceReferenceRegistry::types());

        $this->expectException(BusinessRuleException::class);
        SourceReferenceRegistry::assertValid('made_up_source', 1);
    }

    public function test_a_reference_cannot_have_an_id_without_a_type(): void
    {
        $this->expectException(BusinessRuleException::class);
        SourceReferenceRegistry::assertValid(null, 123);
    }

    public function test_legacy_period_depreciation_reference_may_remain_unkeyed(): void
    {
        SourceReferenceRegistry::assertValid('asset_depreciation', null);
        self::assertTrue(true);
    }
}
