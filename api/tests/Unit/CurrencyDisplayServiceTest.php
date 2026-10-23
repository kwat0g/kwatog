<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Services\CurrencyDisplayService;
use App\Common\Services\SettingsService;
use Mockery;
use PHPUnit\Framework\TestCase;

class CurrencyDisplayServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_formats_pesos_with_the_symbol_used_by_the_spa(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('accounting.functional_currency_code')->andReturn('php');

        self::assertSame('₱125,000.00', (new CurrencyDisplayService($settings))->format('125000'));
    }

    public function test_keeps_other_currency_codes_and_unset_currency_behavior(): void
    {
        $settings = Mockery::mock(SettingsService::class);
        $settings->shouldReceive('get')->with('accounting.functional_currency_code')->andReturn('USD');
        self::assertSame('USD 1,234.50', (new CurrencyDisplayService($settings))->format('1234.5'));

        $settingsWithoutCurrency = Mockery::mock(SettingsService::class);
        $settingsWithoutCurrency->shouldReceive('get')->with('accounting.functional_currency_code')->andReturn(null);
        self::assertSame('1,234.50', (new CurrencyDisplayService($settingsWithoutCurrency))->format('1234.5'));
    }
}
