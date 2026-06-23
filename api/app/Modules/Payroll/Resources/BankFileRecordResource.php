<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\BankFileRecord
 */
class BankFileRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'file_path'        => $this->file_path,
            'format'           => $this->format,
            'record_count'     => $this->record_count,
            'total_amount'     => $this->total_amount,
            'generated_by'     => $this->generator?->name,
            'generated_at'     => optional($this->generated_at)->toIso8601String(),
            'created_at'       => optional($this->created_at)->toIso8601String(),
        ];
    }
}
