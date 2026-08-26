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
}
