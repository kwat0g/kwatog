<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CRM\Support\CustomerOrderQuantityLimit;
use PHPUnit\Framework\TestCase;

class CustomerOrderQuantityLimitTest extends TestCase
{
    public function test_rejects_a_quantity_below_the_customer_product_minimum(): void
    {
        self::assertSame(
            'Minimum order quantity is 5 pcs.',
            CustomerOrderQuantityLimit::error('4', '5.000', '20.000', 'pcs'),
        );
    }

    public function test_rejects_a_quantity_above_the_customer_product_maximum(): void
    {
        self::assertSame(
            'Maximum order quantity is 20 pcs.',
            CustomerOrderQuantityLimit::error('21', '5.000', '20.000', 'pcs'),
        );
        self::assertSame(
            'Maximum order quantity is 200 pcs.',
            CustomerOrderQuantityLimit::error('201', '5', '200', 'pcs'),
        );
    }

    public function test_allows_an_in_range_quantity_and_an_unlimited_maximum(): void
    {
        self::assertNull(CustomerOrderQuantityLimit::error('20', '5.000', '20.000', 'pcs'));
        self::assertNull(CustomerOrderQuantityLimit::error('1000000', '5.000', null, 'pcs'));
    }

    public function test_allows_an_over_maximum_quantity_only_for_a_requested_limit_review(): void
    {
        self::assertSame(
            'Maximum order quantity is 20 pcs.',
            CustomerOrderQuantityLimit::error('25', '5.000', '20.000', 'pcs'),
        );
        self::assertNull(CustomerOrderQuantityLimit::error('25', '5.000', '20.000', 'pcs', allowAboveMaximum: true));
        self::assertSame(
            'Minimum order quantity is 5 pcs.',
            CustomerOrderQuantityLimit::error('4', '5.000', '20.000', 'pcs', allowAboveMaximum: true),
            'A limit review must not bypass the minimum quantity.',
        );
    }
}
