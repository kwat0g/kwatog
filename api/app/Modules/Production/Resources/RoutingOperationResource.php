<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoutingOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'sequence'            => (int) $this->sequence,
            'operation_name'      => $this->operation_name,
            'work_center'         => $this->work_center,
            'machine'             => $this->whenLoaded('machine', fn () => $this->machine ? [
                'id'           => $this->machine->hash_id,
                'machine_code' => $this->machine->machine_code,
                'name'         => $this->machine->name,
            ] : null),
            'mold'                => $this->whenLoaded('mold', fn () => $this->mold ? [
                'id'        => $this->mold->hash_id,
                'mold_code' => $this->mold->mold_code,
                'name'      => $this->mold->name,
            ] : null),
            'setup_time_minutes'  => $this->setup_time_minutes,
            'cycle_time_minutes'  => $this->cycle_time_minutes,
            'description'         => $this->description,
            'qc_required'         => (bool) $this->qc_required,
        ];
    }
}
