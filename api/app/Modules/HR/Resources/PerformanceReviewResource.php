<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use App\Modules\HR\Enums\PerformanceOverallRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerformanceReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'cycle'           => $this->relationRef($this->cycle, ['name' => $this->cycle?->name]),
            'employee'        => $this->relationRef($this->employee, [
                'first_name' => $this->employee?->first_name,
                'last_name'  => $this->employee?->last_name,
            ]),
            'reviewer'        => $this->relationRef($this->reviewer, [
                'first_name' => $this->reviewer?->first_name,
                'last_name'  => $this->reviewer?->last_name,
            ]),
            'status'          => $this->status?->value,
            'status_label'    => $this->status?->label(),
            'ratings'         => $this->ratings,
            'strengths'       => $this->strengths,
            'improvements'    => $this->improvements,
            'goals'           => $this->goals,
            'overall_score'   => $this->overall_score !== null ? (string) $this->overall_score : null,
            'overall_rating'  => $this->overall_rating,
            'overall_rating_label' => $this->overall_rating !== null
                ? PerformanceOverallRating::tryFrom((string) $this->overall_rating)?->label()
                : null,
            'submitted_at'    => $this->submitted_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The SPA treats cycle/employee/reviewer as always-present objects, so emit
     * a stable shape (hash id + labels) rather than dropping the key when the
     * relation is missing.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    private function relationRef(mixed $model, array $attributes): ?array
    {
        if ($model === null) {
            return null;
        }

        return ['id' => $model->hash_id] + $attributes;
    }
}
