<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\CommissionEarning;
use App\Modules\CRM\Requests\StoreCommissionRateRequest;
use App\Modules\CRM\Resources\CommissionEarningResource;
use App\Modules\CRM\Services\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class CommissionController extends Controller
{
    public function __construct(private readonly CommissionService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CommissionEarningResource::collection(
            $this->service->list($request->all())
        );
    }

    public function rates(Request $request): JsonResponse
    {
        return response()->json(
            $this->service->ratesList($request->all())
        );
    }

    public function setRate(StoreCommissionRateRequest $request): JsonResponse
    {
        $rate = $this->service->setRate($request->validated());
        return response()->json(['data' => $rate], 201);
    }

    public function approve(CommissionEarning $earning): CommissionEarningResource
    {
        return new CommissionEarningResource(
            $this->service->approve($earning, request()->user())
        );
    }

    public function batchPaid(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'array', 'min:1']]);

        $decoded = collect($request->input('ids'))->map(fn ($hash) => app('hashids')->decode($hash)[0] ?? null)->filter()->all();

        $count = $this->service->markPaid($decoded, $request->user());
        return response()->json(['paid_count' => $count]);
    }
}
