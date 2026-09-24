<?php

declare(strict_types=1);

namespace App\Modules\Landing\Resources;

use App\Modules\Landing\Models\ContactInquiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContactInquiry */
class ContactInquiryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canManage = $request->user()?->can('crm.inquiries.manage') === true;

        return [
            'id' => $this->hash_id,
            'inquiry_no' => $this->inquiry_no,
            'full_name' => $this->full_name,
            'company' => $this->company,
            'email' => $this->email,
            'phone' => $this->phone,
            'message' => $this->message,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'ip_address' => $canManage ? $this->ip_address : null,
            'user_agent' => $canManage ? $this->user_agent : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
