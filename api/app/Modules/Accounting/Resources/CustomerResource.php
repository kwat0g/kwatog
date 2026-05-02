<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Common\Support\Money;
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
        $creditAvail  = $creditLimit !== '0' ? Money::sub($creditLimit, $creditUsed) : null;

        return [
            'id'                 => $this->hash_id,
            'name'               => $this->name,
            'contact_person'     => $this->contact_person,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'address'            => $this->address,
            'tin'                => $canSeeTin ? $this->tin : $this->maskTin($this->tin),
            'credit_limit'       => $this->credit_limit ? (string) $this->credit_limit : null,
            'credit_used'        => $creditUsedRaw !== null ? Money::round2($creditUsed) : null,
            'credit_available'   => $creditAvail,
            'payment_terms_days' => (int) $this->payment_terms_days,
            'is_active'          => (bool) $this->is_active,
            'invoices_count'     => $this->whenCounted('invoices'),
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'updated_at'         => optional($this->updated_at)->toIso8601String(),
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
