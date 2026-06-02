<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortalShippingDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->hash_id,
            'purchase_order_id' => $this->purchase_order_id,
            'document_type'     => $this->document_type,
            'document_type_label' => match ($this->document_type) {
                'commercial_invoice' => 'Commercial Invoice',
                'packing_list'       => 'Packing List',
                'bill_of_lading'     => 'Bill of Lading',
                'supplier_invoice'   => 'Supplier Invoice',
                default              => ucfirst(str_replace('_', ' ', (string) $this->document_type)),
            },
            'original_filename' => $this->original_filename,
            'file_size_bytes'   => $this->file_size_bytes,
            'file_size_formatted' => $this->file_size_bytes >= 1048576
                ? round($this->file_size_bytes / 1048576, 1) . ' MB'
                : round($this->file_size_bytes / 1024, 1) . ' KB',
            'mime_type'         => $this->mime_type,
            'notes'             => $this->notes,
            'uploaded_by'       => $this->uploaded_by,
            'uploaded_at'       => optional($this->uploaded_at)->toIso8601String(),
            'download_url'      => url("/api/v1/b2b/supplier/shipping-documents/{$this->hash_id}/download"),
        ];
    }
}
