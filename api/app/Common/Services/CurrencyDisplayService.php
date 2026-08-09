<?php

declare(strict_types=1);

namespace App\Common\Services;

final class CurrencyDisplayService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function code(): ?string
    {
        $value = $this->settings->get('accounting.functional_currency_code');
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }

    public function format(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '' || ! is_numeric($amount)) {
            return '—';
        }

        $formatted = number_format((float) $amount, 2, '.', ',');
        $code = $this->code();

        return $code === null ? $formatted : $code.' '.$formatted;
    }
}
