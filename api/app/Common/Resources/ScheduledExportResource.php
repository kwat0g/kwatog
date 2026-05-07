<?php

declare(strict_types=1);

namespace App\Common\Resources;

use App\Common\Enums\ExportFormat;
use App\Common\Enums\ExportFrequency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Common\Models\ScheduledExport
 */
class ScheduledExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'name'         => $this->name,
            'module'       => $this->module,
            'columns'      => $this->columns ?? [],
            'filters'      => $this->filters ?? [],
            'format'       => $this->format instanceof ExportFormat ? $this->format->value : (string) $this->format,
            'frequency'    => $this->frequency instanceof ExportFrequency ? $this->frequency->value : (string) $this->frequency,
            'day_of_week'  => $this->day_of_week,
            'day_of_month' => $this->day_of_month,
            'time_of_day'  => $this->time_of_day,
            'recipients'   => $this->recipients ?? [],
            'last_run_at'  => optional($this->last_run_at)->toIso8601String(),
            'next_run_at'  => optional($this->next_run_at)->toIso8601String(),
            'is_active'    => (bool) $this->is_active,
            'owner'        => $this->whenLoaded('owner', function () {
                return $this->owner ? [
                    'id'   => $this->owner->hash_id,
                    'name' => $this->owner->name,
                ] : null;
            }),
            'created_at'   => optional($this->created_at)->toIso8601String(),
        ];
    }
}
