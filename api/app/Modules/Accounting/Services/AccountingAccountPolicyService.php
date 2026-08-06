<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\SettingsService;

final class AccountingAccountPolicyService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function ar(): string { return $this->settings->requiredString('accounting.accounts.ar_code'); }
    public function ap(): string { return $this->settings->requiredString('accounting.accounts.ap_code'); }
    public function vatOutput(): string { return $this->settings->requiredString('accounting.accounts.vat_output_code'); }
    public function vatInput(): string { return $this->settings->requiredString('accounting.accounts.vat_input_code'); }
    public function discount(): string { return $this->settings->requiredString('accounting.accounts.discount_code'); }
    public function revenue(): string { return $this->settings->requiredString('accounting.default_sales_revenue_account_code'); }
}
