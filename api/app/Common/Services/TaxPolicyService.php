<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\BusinessRuleException;

class TaxPolicyService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function vatRate(): string
    {
        $value = $this->settings->get('tax.ph.vat_rate', '0.12');
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            $value = '0.12';
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0.12';
    }

    public function isVatRegistered(): bool
    {
        try {
            $status = trim((string) $this->settings->get('company.vat_status', 'VAT Registered'));
            return strcasecmp($status, 'VAT Registered') === 0;
        } catch (\Throwable) {
            return true;
        }
    }
}
