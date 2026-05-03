<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Requests\InitiateSeparationRequest;
use App\Modules\HR\Resources\ClearanceResource;
use App\Modules\HR\Services\FinalPayService;
use App\Modules\HR\Services\SeparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SeparationController
{
    public function __construct(
        private readonly SeparationService $service,
        private readonly FinalPayService $finalPay,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ClearanceResource::collection($this->service->list($request->query()));
    }

    public function show(Clearance $clearance): ClearanceResource
    {
        return new ClearanceResource($this->service->show($clearance));
    }

    /**
     * POST /employees/{employee}/separation
     */
    public function initiate(InitiateSeparationRequest $request, Employee $employee): JsonResponse
    {
        $clearance = $this->service->initiate($employee, $request->validated(), $request->user());
        return (new ClearanceResource($clearance))->response()->setStatusCode(201);
    }

    /**
     * PATCH /clearances/{clearance}/items
     */
    public function signItem(Request $request, Clearance $clearance): ClearanceResource
    {
        abort_unless($request->user()?->can('hr.clearance.sign'), 403);
        $data = $request->validate([
            'item_key' => ['required', 'string', 'max:64'],
            'remarks'  => ['nullable', 'string', 'max:5000'],
        ]);
        return new ClearanceResource(
            $this->service->signItem(
                $clearance,
                (string) $data['item_key'],
                $request->user(),
                $data['remarks'] ?? null,
            )
        );
    }

    /**
     * POST /clearances/{clearance}/final-pay/compute
     */
    public function computeFinalPay(Request $request, Clearance $clearance): ClearanceResource
    {
        abort_unless($request->user()?->can('hr.separation.finalize'), 403);
        return new ClearanceResource($this->service->show($this->finalPay->compute($clearance)));
    }

    /**
     * PATCH /clearances/{clearance}/finalize
     */
    public function finalize(Request $request, Clearance $clearance): ClearanceResource
    {
        abort_unless($request->user()?->can('hr.separation.finalize'), 403);
        return new ClearanceResource($this->service->finalize($clearance, $request->user(), $this->finalPay));
    }
}
