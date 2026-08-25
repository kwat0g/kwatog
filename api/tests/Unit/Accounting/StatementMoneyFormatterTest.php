<?php

declare(strict_types=1);

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Services\StatementMoneyFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StatementMoneyFormatterTest extends TestCase
{
    public function test_formats_large_decimal_strings_without_float_conversion(): void
    {
        $formatter = new StatementMoneyFormatter();

        self::assertSame('9,999,999,999,999,999.99', $formatter->format('9999999999999999.99'));
        self::assertSame('-1,234,567,890,123.46', $formatter->format('-1234567890123.455'));
    }

    public function test_preserves_scale_and_rounds_half_up_as_a_string(): void
    {
        $formatter = new StatementMoneyFormatter();

        self::assertSame('0.00', $formatter->format('0'));
        self::assertSame('12.30', $formatter->format('12.3'));
        self::assertSame('12.35', $formatter->format('12.345'));
        self::assertSame('-12.35', $formatter->format('-12.345'));
    }

    public function test_rejects_non_decimal_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new StatementMoneyFormatter())->format('1e6');
    }
}
