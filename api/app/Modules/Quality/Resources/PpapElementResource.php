<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PpapElementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $submission = $this->relationLoaded('submission') ? $this->getRelation('submission') : null;
        $canManage = $viewer instanceof User && $viewer->hasPermission('quality.ppap.manage');

        return [
            'id'            => $this->hash_id,
            'element_type'  => $this->element_type instanceof \BackedEnum ? $this->element_type->value : $this->element_type,
            'element_label' => $this->element_type instanceof \App\Modules\Quality\Enums\PpapElementType ? $this->element_type->label() : null,
            'status'        => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'status_label'  => $this->status instanceof \App\Modules\Quality\Enums\PpapElementStatus ? $this->status->label() : null,
            'document_path' => $this->document_path,
            'notes'         => $this->notes,
            'permissions'   => [
                'view' => $viewer instanceof User && $viewer->hasPermission('quality.ppap.view'),
                'manage' => $canManage,
            ],
            'available_actions' => [
                'update' => $canManage
                    && $submission instanceof PpapSubmission
                    && $submission->status !== PpapStatus::Approved,
            ],
        ];
    }
}
