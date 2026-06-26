<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'application_number' => $this->application_number,
            'tracking_code'      => $this->tracking_code,
            'first_name'         => $this->first_name,
            'last_name'          => $this->last_name,
            'full_name'          => $this->full_name,
            'email'              => $this->email,
            'phone'              => $this->phone,
            'cover_letter'       => $this->cover_letter,
            'stage'              => $this->stage?->value,
            'stage_label'        => $this->stage?->label(),
            'rejected_at_stage'  => $this->rejected_at_stage,
            'rejection_reason'   => $this->rejection_reason,
            'applied_at'         => $this->applied_at?->toIso8601String(),
            'job_posting'        => new JobPostingResource($this->whenLoaded('jobPosting')),
            'interviews'         => ApplicationInterviewResource::collection($this->whenLoaded('interviews')),
            'notes'              => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($n) => [
                'id'   => $n->id,
                'body' => $n->body,
                'user' => ['id' => $n->user->hash_id, 'name' => $n->user->name],
                'created_at' => $n->created_at?->toIso8601String(),
            ])),
            'converted_employee' => $this->whenLoaded('convertedEmployee', fn () => [
                'id'          => $this->convertedEmployee->hash_id,
                'employee_no' => $this->convertedEmployee->employee_no,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
