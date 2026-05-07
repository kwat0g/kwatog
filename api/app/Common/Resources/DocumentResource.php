<?php

declare(strict_types=1);

namespace App\Common\Resources;

use App\Common\Enums\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Common\Models\Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $type = $this->document_type instanceof DocumentType
            ? $this->document_type
            : (DocumentType::tryFrom((string) $this->document_type));

        return [
            'id'              => $this->hash_id,
            'document_type'   => $type?->value ?? (string) $this->document_type,
            'document_label'  => $type?->label() ?? ucwords((string) $this->document_type),
            'file_name'       => $this->file_name,
            'file_size'       => (int) $this->file_size,
            'mime_type'       => $this->mime_type,
            'is_confidential' => (bool) $this->is_confidential,
            'generated_at'    => optional($this->generated_at)->toIso8601String(),
            'generated_by'    => $this->whenLoaded('generatedBy', function () {
                return $this->generatedBy ? [
                    'id'   => $this->generatedBy->hash_id,
                    'name' => $this->generatedBy->name,
                ] : null;
            }),
            'view_url'        => route('documents.view', ['document' => $this->hash_id]),
            'download_url'    => route('documents.download', ['document' => $this->hash_id]),
        ];
    }
}
