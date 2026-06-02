<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DemandForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'forecast_year'       => (int) $this->forecast_year,
            'forecast_month'      => (int) $this->forecast_month,
            'method'              => $this->method,
            'forecasted_quantity' => (float) $this->forecasted_quantity,
            'confidence_level'    => $this->confidence_level !== null ? (float) $this->confidence_level : null,
            'actual_quantity'     => $this->actual_quantity !== null ? (float) $this->actual_quantity : null,
            'variance'            => $this->variance !== null ? (float) $this->variance : null,
            'product'             => $this->whenLoaded('product', fn () => $this->product ? [
                'id'          => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name'        => $this->product->name,
            ] : null),
            'customer'            => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id'   => $this->customer->hash_id,
                'name' => $this->customer->name,
            ] : null),
            'creator'             => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'created_at'          => optional($this->created_at)?->toISOString(),
            'updated_at'          => optional($this->updated_at)?->toISOString(),
        ];
    }
}
