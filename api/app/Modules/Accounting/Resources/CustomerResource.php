<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Common\Support\Money;
use App\Common\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeTin = $user?->hasPermission('accounting.customers.manage') ?? false;

        // Strict-mode safe: read from raw attributes since `credit_used`
        // is only added by CustomerService::list() / ::show() (withSum).
        $attrs        = $this->resource->getAttributes();
        $creditUsedRaw = $attrs['credit_used'] ?? null;
        $creditLimit  = (string) ($this->credit_limit ?? '0');
        $creditUsed   = (string) ($creditUsedRaw ?? '0');

        // A zero limit means "no limit is enforced" (SalesOrderService::
        // checkCreditLimit). This used to be detected with `!== '0'`, but the
        // model's decimal:2 cast renders a stored 0.00 as the string '0.00',
        // which is not '0' — so the guard passed and the ratio below divided by
        // zero. DivisionByZeroError is a 500, and `credit_used` is attached on
        // every list and show, so one customer with credit_limit = 0.00 and at
        // least one invoice took down GET /customers entirely. Compare as money.
        $hasLimit     = ! Money::isZero($creditLimit);
        $creditAvail  = $hasLimit ? Money::sub($creditLimit, $creditUsed) : null;
        $warningRatio = app(SettingsService::class)->requiredFloat('accounting.customer_credit.warning_ratio', 0, 1);
        // used/limit >= ratio, restated as used >= limit*ratio so there is no
        // division at all and the threshold stays exact peso arithmetic.
        // number_format pins the ratio to a decimal string: a small float like
        // 1.0E-5 stringifies to scientific notation, which BCMath rejects.
        $creditWarning = $creditUsedRaw !== null && $hasLimit
            && Money::gte($creditUsed, Money::mul($creditLimit, number_format($warningRatio, 6, '.', '')));

        return [
            'id'                 => $this->hash_id,
            'name'               => $this->name,
            'code'               => $this->code,
            'contact_person'     => $this->contact_person,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'address'            => $this->address,
            'tin'                => $canSeeTin ? $this->tin : $this->maskTin($this->tin),
            'credit_limit'       => $this->credit_limit ? (string) $this->credit_limit : null,
            'credit_used'        => $creditUsedRaw !== null ? Money::round2($creditUsed) : null,
            'credit_available'   => $creditAvail,
            'credit_warning_ratio' => $warningRatio,
            'credit_warning'     => $creditWarning,
            'payment_terms_days' => (int) $this->payment_terms_days,
            'is_active'          => (bool) $this->is_active,
            'invoices_count'     => $this->whenCounted('invoices'),
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
            'deleted_at'         => optional($this->deleted_at)?->toIso8601String(),
        ];
    }

    private function maskTin(?string $tin): ?string
    {
        if (! $tin) return null;
        $len = mb_strlen($tin);
        if ($len <= 4) return str_repeat('•', $len);
        return str_repeat('•', $len - 4) . mb_substr($tin, -4);
    }
}
