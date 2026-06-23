<?php

declare(strict_types=1);

namespace App\Modules\Assets\Resources;

use App\Modules\Assets\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->hash_id,
            'asset_code'               => $this->asset_code,
            'name'                     => $this->name,
            'description'              => $this->description,
            'category'                 => $this->category instanceof \BackedEnum ? $this->category->value : $this->category,
            'department'               => $this->whenLoaded('department', fn () => $this->department ? [
                'id'   => $this->department->hash_id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ] : null),
            'acquisition_date'         => optional($this->acquisition_date)?->toDateString(),
            'acquisition_cost'         => (string) $this->acquisition_cost,
            'useful_life_years'        => (int) $this->useful_life_years,
            'depreciation_method'      => $this->depreciation_method instanceof \BackedEnum ? $this->depreciation_method->value : $this->depreciation_method,
            'depreciation_method_label' => $this->depreciation_method instanceof \App\Modules\Assets\Enums\DepreciationMethod ? $this->depreciation_method->label() : null,
            'salvage_value'            => (string) $this->salvage_value,
            'accumulated_depreciation' => (string) $this->accumulated_depreciation,
            'monthly_depreciation'     => $this->monthly_depreciation,
            'book_value'               => $this->book_value,
            'status'                   => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'disposed_date'            => optional($this->disposed_date)?->toDateString(),
            'disposal_amount'          => $this->disposal_amount !== null ? (string) $this->disposal_amount : null,
            'location'                 => $this->location,
            'insurance_policy_no'      => $this->insurance_policy_no,
            'insurance_provider'       => $this->insurance_provider,
            'insurance_expiry'         => optional($this->insurance_expiry)?->toDateString(),
            'insured_value'            => $this->insured_value !== null ? (string) $this->insured_value : null,
            'depreciations'            => $this->whenLoaded('depreciations', fn () => $this->depreciations->map(fn ($d) => [
                'id'                  => $d->hash_id,
                'period_year'         => (int) $d->period_year,
                'period_month'        => (int) $d->period_month,
                'depreciation_amount' => (string) $d->depreciation_amount,
                'accumulated_after'   => (string) $d->accumulated_after,
                'journal_entry_id'    => $d->journal_entry_id ? \App\Modules\Accounting\Models\JournalEntry::find($d->journal_entry_id)?->hash_id : null,
                'created_at'          => optional($d->created_at)?->toISOString(),
            ])),
            'created_at'               => optional($this->created_at)?->toISOString(),
            'updated_at'               => optional($this->updated_at)?->toISOString(),
        ];
    }
}
