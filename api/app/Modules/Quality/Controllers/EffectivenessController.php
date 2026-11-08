<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Exceptions\NcrActionStateConflictException;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\NcrActionResource;
use App\Modules\Quality\Services\EffectivenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
    public function verify(Request $request, NonConformanceReport $ncr, NcrAction $action): NcrActionResource|JsonResponse
    {
        abort_unless($action->ncr_id === $ncr->id, 404);

        $data = $request->validate([
            'effectiveness_status' => ['required', Rule::in(EffectivenessStatus::values())],
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $updated = $this->service->verifyAction(
                $action,
                $request->user(),
                EffectivenessStatus::from($data['effectiveness_status']),
                $data['notes'],
            );
        } catch (NcrActionStateConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => [$exception->errorKey() => [$exception->getMessage()]],
                'code' => $exception->errorCode(),
            ], 409);
        }

        return new NcrActionResource($updated);
    }

    /**
     * List corrective/preventive actions whose effectiveness check is due.
     */
    public function dueIndex(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'signal' => ['nullable', Rule::in(['awaiting_my_action'])],
        ]);

        $due = $this->service->dueChecksQuery(
            ($data['signal'] ?? null) === 'awaiting_my_action' ? (int) $request->user()->id : null,
            ($data['signal'] ?? null) === 'awaiting_my_action',
        )
            ->with(['owner:id,name', 'performer:id,name', 'ncr:id,ncr_number,status'])
            ->orderBy('next_effectiveness_check_at')
            ->paginate((int) ($data['per_page'] ?? 25));

        return NcrActionResource::collection($due);
    }
}
