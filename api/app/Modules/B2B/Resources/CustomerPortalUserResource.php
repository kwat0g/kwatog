<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerPortalUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'name'                => $this->name,
            'email'               => $this->email,
            'is_active'           => (bool) $this->is_active,
            'must_change_password' => (bool) $this->must_change_password,
            'status'              => $this->portalStatus(),
            'deleted_at'          => optional($this->deleted_at)->toIso8601String(),
            'customer_id'         => app('hashids')->encode((int) $this->customer_id),
            'customer_name'       => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer'            => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'customer_terms'      => $this->whenLoaded('customer', fn () => $this->customer ? [
                'allowed_payment_methods' => $this->customer->allowed_payment_methods,
                'shipping_payer' => $this->customer->shipping_payer,
                'agreement_starts_on' => $this->customer->agreement_starts_on?->toDateString(),
                'agreement_ends_on' => $this->customer->agreement_ends_on?->toDateString(),
                'agreement_status' => $this->agreementStatus(),
                'payment_terms_days' => (int) $this->customer->payment_terms_days,
                'warranty_defaults' => [
                    'warranty_duration_days' => $this->customer->warranty_duration_days,
                    'warranty_report_window_days' => $this->customer->warranty_report_window_days,
                    'warranty_start_basis' => $this->customer->warranty_start_basis,
                    'warranty_covered_issues' => $this->customer->warranty_covered_issues,
                    'warranty_remedies' => $this->customer->warranty_remedies,
                ],
            ] : null),
            'last_login_at'       => optional($this->last_login_at)->toIso8601String(),
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }

    private function portalStatus(): string
    {
        if ($this->trashed() || ! $this->is_active
            || ($this->relationLoaded('customer') && (! $this->customer || ! $this->customer->is_active))) return 'inactive';
        if ($this->isLocked()) return 'locked';
        if ($this->must_change_password) return 'pending';

        return 'active';
    }

    private function agreementStatus(): string
    {
        $startsOn = $this->customer->agreement_starts_on?->toDateString();
        $endsOn = $this->customer->agreement_ends_on?->toDateString();
        if ($startsOn === null && $endsOn === null) return 'not_recorded';
        if ($startsOn !== null && today()->toDateString() < $startsOn) return 'not_yet_effective';
        if ($endsOn !== null && today()->toDateString() > $endsOn) return 'expired';
        return 'in_effect';
    }
}
