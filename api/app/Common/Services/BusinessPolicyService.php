<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Exceptions\BusinessRuleException;

class BusinessPolicyService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function customerPaymentTermsDays(): int
    {
        return $this->nonNegativeInt('sales.default_customer_payment_terms_days');
    }

    public function vendorPaymentTermsDays(): int
    {
        return $this->nonNegativeInt('purchasing.default_vendor_payment_terms_days');
    }

    public function salesDeliveryLeadDays(): int
    {
        return $this->nonNegativeInt('sales.default_delivery_lead_days');
    }

    public function mrpDefaultLeadTimeDays(): int
    {
        return $this->positiveInt('mrp.default_lead_time_days');
    }

    public function mrpWorkOrderNormalPriority(): int
    {
        return $this->nonNegativeInt('mrp.work_order.normal_priority');
    }

    public function purchaseOrderVpThreshold(): float
    {
        $value = $this->settings->get('approval.po.vp_threshold', '__missing_business_policy__');
        if (! is_numeric($value) || (float) $value < 0) {
            throw new BusinessRuleException('Required business setting approval.po.vp_threshold is missing or invalid.');
        }

        return (float) $value;
    }

    public function functionalCurrencyCode(): ?string
    {
        return $this->optionalCode('accounting.functional_currency_code');
    }

    public function reportingCurrencyCode(): ?string
    {
        return $this->optionalCode('accounting.reporting_currency_code');
    }

    public function translationAdjustmentAccountCode(): string
    {
        return $this->settings->requiredString('accounting.statements.translation_adjustment_code');
    }

    /** @return array<string, int|float|string|null> */
    public function defaults(): array
    {
        return [
            'customer_payment_terms_days' => $this->customerPaymentTermsDays(),
            'vendor_payment_terms_days' => $this->vendorPaymentTermsDays(),
            'sales_delivery_lead_days' => $this->salesDeliveryLeadDays(),
            'mrp_default_lead_time_days' => $this->mrpDefaultLeadTimeDays(),
            'mrp_work_order_normal_priority' => $this->mrpWorkOrderNormalPriority(),
            'purchase_order_vp_threshold' => $this->purchaseOrderVpThreshold(),
            'functional_currency_code' => $this->functionalCurrencyCode(),
            'reporting_currency_code' => $this->reportingCurrencyCode(),
            'translation_adjustment_account_code' => $this->translationAdjustmentAccountCode(),
        ];
    }

    private function nonNegativeInt(string $key): int
    {
        $value = $this->settings->get($key, '__missing_business_policy__');
        if (! is_numeric($value) || (int) $value < 0) {
            throw new BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (int) $value;
    }

    private function positiveInt(string $key): int
    {
        $value = $this->nonNegativeInt($key);
        if ($value === 0) {
            throw new BusinessRuleException("Required business setting {$key} must be greater than zero.");
        }

        return $value;
    }

    private function optionalCode(string $key): ?string
    {
        $value = $this->settings->get($key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return strtoupper(trim($value));
    }
}
