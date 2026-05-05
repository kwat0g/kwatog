<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MrpRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->hash_id,
            'run_at'                 => optional($this->run_at)->toIso8601String(),
            'triggered_by'           => $this->triggered_by instanceof \BackedEnum ? $this->triggered_by->value : (string) $this->triggered_by,
            'triggered_by_user'      => $this->whenLoaded('user', fn () => $this->user ? [
                'id'   => $this->user->hash_id,
                'name' => $this->user->name,
            ] : null),
            'sales_orders_evaluated' => (int) $this->sales_orders_evaluated,
            'shortages_found'        => (int) $this->shortages_found,
            'prs_created'            => (int) $this->prs_created,
            'prs_updated'            => (int) $this->prs_updated,
            'plans_generated'        => (int) $this->plans_generated,
            'duration_ms'            => $this->duration_ms !== null ? (int) $this->duration_ms : null,
            'status'                 => $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status,
            'error_message'          => $this->error_message,
            'summary'                => $this->summary ?? [],
            'created_at'             => optional($this->created_at)->toIso8601String(),
        ];
    }
}
