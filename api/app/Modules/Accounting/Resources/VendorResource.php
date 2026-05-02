<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $canSeeTin = $user?->hasPermission('accounting.vendors.manage') ?? false;

        // Strict-mode safe: not every endpoint pre-computes `open_balance`
        // (only VendorService::list() and ::show() add it via withSum).
        $openBalance = $this->resource->getAttributes()['open_balance'] ?? null;

        return [
            'id'                 => $this->hash_id,
            'name'               => $this->name,
            'contact_person'     => $this->contact_person,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'address'            => $this->address,
            'tin'                => $canSeeTin ? $this->tin : $this->maskTin($this->tin),
            'payment_terms_days' => (int) $this->payment_terms_days,
            'is_active'          => (bool) $this->is_active,
            'open_balance'       => $openBalance !== null ? (string) $openBalance : null,
            'bills_count'        => $this->whenCounted('bills'),
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
