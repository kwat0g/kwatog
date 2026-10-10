<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Requests;

class ShipmentPlanRequest extends AssignDeliveryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:scheduled_date'],
            'carrier' => ['required', 'string', 'max:100'],
            'dispatch' => ['required', 'boolean'],
            'loading_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,heic,webp', 'max:10240'],
        ]);
    }
}
