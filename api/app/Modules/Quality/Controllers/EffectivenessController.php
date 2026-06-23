<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\NcrActionResource;
use App\Modules\Quality\Services\EffectivenessService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CAPA effectiveness verification endpoints (IATF 16949 §10.2.1).
 */
class EffectivenessController
{
    public function __construct(private readonly EffectivenessService $service) {}

    /**
     * Record an effectiveness verdict for a corrective/preventive action.
     */
    public function verify(Request $request, NonConformanceReport $ncr, NcrAction $action): NcrActionResource
    {
        abort_unless($action->ncr_id === $ncr->id, 404);

        $data = $request->validate([
            'effectiveness_status' => ['required', Rule::in(EffectivenessStatus::values())],
            'notes'                => ['required', 'string', 'max:2000'],
        ]);

        $updated = $this->service->verifyAction(
            $action,
            $request->user(),
            EffectivenessStatus::from($data['effectiveness_status']),
            $data['notes'],
        );

        return new NcrActionResource($updated);
    }

    /**
     * List corrective/preventive actions whose effectiveness check is due.
     */
    public function dueIndex(Request $request): AnonymousResourceCollection
    {
        $due = NcrAction::query()
            ->with(['owner:id,name', 'performer:id,name', 'ncr:id,ncr_number'])
            ->where('effectiveness_status', EffectivenessStatus::PendingVerification->value)
            ->whereNotNull('next_effectiveness_check_at')
            ->whereDate('next_effectiveness_check_at', '<=', now()->toDateString())
            ->orderBy('next_effectiveness_check_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return NcrActionResource::collection($due);
    }
}
