<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->hash_id,
            'document_type' => $this->document_type,
            'file_name'     => $this->file_name,
            'uploaded_at'   => optional($this->uploaded_at)->toIso8601String(),
        ];
    }
}
