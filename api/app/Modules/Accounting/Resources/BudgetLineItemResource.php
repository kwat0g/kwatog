<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetLineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'budget_id'    => $this->budget_id === null ? null : app('hashids')->encode((int) $this->budget_id),
            'account_id'   => $this->account_id === null ? null : app('hashids')->encode((int) $this->account_id),
            'account'      => $this->whenLoaded('account', fn () => [
                'id'   => $this->account?->hash_id,
                'code' => $this->account?->code,
                'name' => $this->account?->name,
            ]),
            'jan'  => (string) $this->jan,
            'feb'  => (string) $this->feb,
            'mar'  => (string) $this->mar,
            'apr'  => (string) $this->apr,
            'may'  => (string) $this->may,
            'jun'  => (string) $this->jun,
            'jul'  => (string) $this->jul,
            'aug'  => (string) $this->aug,
            'sep'  => (string) $this->sep,
            'oct'  => (string) $this->oct,
            'nov'  => (string) $this->nov,
            'dec'  => (string) $this->dec,
            'annual_total'   => (string) $this->annual_total,
            'actual_total'   => (string) $this->actual_total,
            'variance'       => (string) $this->variance,
        ];
    }
}
