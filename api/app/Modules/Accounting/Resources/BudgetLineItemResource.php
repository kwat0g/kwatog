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
            'budget_id'    => $this->budget_id,
            'account_id'   => $this->account_id,
            'account'      => $this->whenLoaded('account', fn () => [
                'id'   => $this->account?->hash_id,
                'code' => $this->account?->code,
                'name' => $this->account?->name,
            ]),
            'jan'  => (float) $this->jan,
            'feb'  => (float) $this->feb,
            'mar'  => (float) $this->mar,
            'apr'  => (float) $this->apr,
            'may'  => (float) $this->may,
            'jun'  => (float) $this->jun,
            'jul'  => (float) $this->jul,
            'aug'  => (float) $this->aug,
            'sep'  => (float) $this->sep,
            'oct'  => (float) $this->oct,
            'nov'  => (float) $this->nov,
            'dec'  => (float) $this->dec,
            'annual_total'   => (float) $this->annual_total,
            'actual_total'   => (float) $this->actual_total,
            'variance'       => (float) $this->variance,
        ];
    }
}
