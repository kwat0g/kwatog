<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\BusinessRuleException;

class TaxPolicyService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function vatRate(): string
    {
        $value = $this->settings->get('tax.ph.vat_rate', '__missing_tax_policy__');
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
            throw new BusinessRuleException('Required setting tax.ph.vat_rate is missing or invalid.');
        }

        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
