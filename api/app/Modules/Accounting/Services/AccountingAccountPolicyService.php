<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\AccountType;

/**
 * The configured GL control accounts, and the COA classification each one is
 * required to have.
 *
 * A configured code is deployment data, so "the AP code" can be pointed at any
 * account an operator likes. Checking only that the account is active lets an
 * active account of the wrong type receive a semantically specific posting —
 * a balanced journal with incorrect financial classification, which is far
 * harder to find than a failed post. `controlAccountId()` is therefore the one
 * lookup every GL writer should use: it resolves the code and asserts the
 * classification at the same boundary.
 *
 * The type map lives here rather than at each call site so Bill, Invoice and
 * Credit Note cannot drift about what an AP or VAT code is allowed to be.
 */
final class AccountingAccountPolicyService
{
    /** @var array<string, AccountType> */
    private const SETTING_TYPES = [
        'accounting.default_sales_revenue_account_code' => AccountType::Revenue,
        'accounting.default_expense_account_code' => AccountType::Expense,
        'accounting.accounts.ar_code' => AccountType::Asset,
        'accounting.accounts.ap_code' => AccountType::Liability,
        'accounting.accounts.vat_output_code' => AccountType::Liability,
        'accounting.accounts.vat_input_code' => AccountType::Asset,
        'accounting.accounts.discount_code' => AccountType::Revenue,
        'accounting.accounts.grni_code' => AccountType::Liability,
        'accounting.accounts.landed_cost_clearing_code' => AccountType::Liability,
        'accounting.accounts.inventory_raw_material_code' => AccountType::Asset,
        'accounting.accounts.inventory_finished_goods_code' => AccountType::Asset,
        'accounting.accounts.inventory_packaging_code' => AccountType::Asset,
        'accounting.accounts.inventory_spare_parts_code' => AccountType::Asset,
        'accounting.accounts.purchase_return_expense_code' => AccountType::Expense,
        'accounting.accounts.final_pay_salary_expense_code' => AccountType::Expense,
        'accounting.accounts.cash_code' => AccountType::Asset,
        'accounting.accounts.loans_payable_code' => AccountType::Liability,
        'accounting.accounts.accrued_expense_code' => AccountType::Liability,
        'accounting.accounts.asset_cash_code' => AccountType::Asset,
        'accounting.accounts.asset_accumulated_depreciation_code' => AccountType::Asset,
        'accounting.accounts.asset_cost_code' => AccountType::Asset,
        'accounting.accounts.asset_disposal_loss_code' => AccountType::Expense,
        'accounting.accounts.asset_disposal_gain_code' => AccountType::Revenue,
        'accounting.accounts.depreciation_expense_code' => AccountType::Expense,
        'accounting.accounts.material_consumption_code' => AccountType::Expense,
        'accounting.accounts.inventory_adjustment_code' => AccountType::Expense,
        'accounting.accounts.sss_payable_code' => AccountType::Liability,
        'accounting.accounts.philhealth_payable_code' => AccountType::Liability,
        'accounting.accounts.pagibig_payable_code' => AccountType::Liability,
        'accounting.accounts.withholding_tax_payable_code' => AccountType::Liability,
        'accounting.accounts.thirteenth_month_payable_code' => AccountType::Liability,
        'accounting.accounts.salary_expense_code' => AccountType::Expense,
        'accounting.accounts.overtime_expense_code' => AccountType::Expense,
        'accounting.accounts.thirteenth_month_expense_code' => AccountType::Expense,
        'accounting.accounts.production_salary_expense_code' => AccountType::Expense,
        'accounting.accounts.production_overtime_expense_code' => AccountType::Expense,
        'accounting.accounts.production_thirteenth_month_expense_code' => AccountType::Expense,
        'accounting.accounts.sss_employer_expense_code' => AccountType::Expense,
        'accounting.accounts.philhealth_employer_expense_code' => AccountType::Expense,
        'accounting.accounts.pagibig_employer_expense_code' => AccountType::Expense,
        'accounting.accounts.payroll_cash_code' => AccountType::Asset,
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly PostingAccountResolver $postingAccounts,
    ) {}

    public function ar(): string { return $this->settings->requiredString('accounting.accounts.ar_code'); }
    public function ap(): string { return $this->settings->requiredString('accounting.accounts.ap_code'); }
    public function vatOutput(): string { return $this->settings->requiredString('accounting.accounts.vat_output_code'); }
    public function vatInput(): string { return $this->settings->requiredString('accounting.accounts.vat_input_code'); }
    public function discount(): string { return $this->settings->requiredString('accounting.accounts.discount_code'); }
    public function revenue(): string { return $this->settings->requiredString('accounting.default_sales_revenue_account_code'); }

    /**
     * The classification a configured control-account code must have.
     *
     * Returns null for a code this policy does not own, so a caller resolving
     * something else (a per-product revenue account, an operator-chosen expense
     * line) is not forced through a rule that was never written for it.
     */
    public function typeFor(string $code): ?AccountType
    {
        return match ($code) {
            $this->ar() => AccountType::Asset,
            $this->ap() => AccountType::Liability,
            $this->vatOutput() => AccountType::Liability,
            $this->vatInput() => AccountType::Asset,
            $this->discount() => AccountType::Revenue,
            default => null,
        };
    }

    /**
     * Resolve a configured control-account code to the id of an active account
     * of the classification that role requires.
     *
     * A missing code is a deployment fault (RuntimeException, from the
     * resolver); an inactive or wrongly-classified account is a business-rule
     * failure. Both happen before any journal line is built.
     */
    public function controlAccountId(string $code): int
    {
        $type = $this->typeFor($code);

        return $type === null
            ? $this->postingAccounts->configuredIdByCode($code)
            : $this->postingAccounts->configuredIdByCode($code, $type);
    }

    public function controlAccountIdForSetting(string $settingKey): int
    {
        $code = $this->settings->requiredString($settingKey);
        $type = self::SETTING_TYPES[$settingKey] ?? null;

        return $type === null
            ? $this->postingAccounts->configuredIdByCode($code)
            : $this->postingAccounts->configuredIdByCode($code, $type);
    }
}
