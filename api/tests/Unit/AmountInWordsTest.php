<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Support\AmountInWords;
use Tests\TestCase;

class AmountInWordsTest extends TestCase
{
    public function test_zero(): void
    {
        $this->assertStringContainsString('Pesos and 00/100', AmountInWords::peso(0));
    }

    public function test_one_thousand(): void
    {
        $words = AmountInWords::peso(1000.00);
        $this->assertStringContainsStringIgnoringCase('Thousand', $words);
        $this->assertStringContainsString('00/100 Only', $words);
    }

    public function test_with_centavos(): void
    {
        $words = AmountInWords::peso(123.45);
        $this->assertStringContainsString('45/100', $words);
    }
}
