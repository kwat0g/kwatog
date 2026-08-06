<?php

declare(strict_types=1);

namespace App\Common\Services;

final class CurrencyDisplayService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function code(): string
    {
        $code = (string) $this->settings->get('accounting.functional_currency_code', 'PHP');
        return strtoupper(trim($code) !== '' ? $code : 'PHP');
    }

    public function format(float|int|string|null $amount): string
    {
        return $this->code().' '.number_format((float) ($amount ?? 0), 2, '.', ',');
    }
}
