<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\BusinessRuleException;

class TaxPolicyService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function vatRate(): ?string
    {
        $value = $this->settings->get('tax.ph.vat_rate');
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw new BusinessRuleException('Required setting tax.ph.vat_rate is missing or invalid.');
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
    }

    public function requiredVatRate(): string
    {
        $rate = $this->vatRate();
        if ($rate === null) {
            throw new BusinessRuleException('Required setting tax.ph.vat_rate is missing or invalid.');
        }

        return $rate;
    }

    public function isVatRegistered(): bool
    {
        try {
            $status = trim((string) $this->settings->get('company.vat_status'));
            return strcasecmp($status, 'VAT Registered') === 0 && $this->vatRate() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
