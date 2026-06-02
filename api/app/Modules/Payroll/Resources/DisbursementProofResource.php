<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Payroll\Models\DisbursementProof
 */
class DisbursementProofResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->hash_id,
            'proof_type'           => $this->proof_type,
            'file_name'            => $this->file_name,
            'bank_name'            => $this->bank_name,
            'transaction_reference' => $this->transaction_reference,
            'disbursed_amount'     => $this->disbursed_amount,
            'disbursement_date'    => optional($this->disbursement_date)->toDateString(),
            'notes'                => $this->notes,
            'uploader'             => $this->whenLoaded('uploader', fn () => [
                'id'   => $this->uploader?->hash_id,
                'name' => $this->uploader?->name,
            ]),
            'created_at'           => optional($this->created_at)->toIso8601String(),
        ];
    }
}
