<?php

declare(strict_types=1);

namespace App\Modules\MRP\Controllers;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Resources\MrpRunResource;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\MRP\Services\MrpRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MrpRunController
{
    public function __construct(
        private readonly MrpRunService $runs,
        private readonly MrpEngineService $engine,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MrpRunResource::collection($this->runs->list($request->query()));
    }

    public function latest(): JsonResponse
    {
        $run = $this->runs->latest();
        if (! $run) {
            return response()->json(['data' => null]);
        }
        return response()->json(['data' => (new MrpRunResource($run))->toArray(request())]);
    }

    /**
     * POST /api/v1/mrp/runs — Manual trigger by PPC Head.
     * Permission `mrp.runs.trigger` enforced via route middleware.
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $run = $this->engine->runForAllActiveSalesOrders(MrpRunTrigger::Manual, $userId);

        return response()
            ->json(['data' => (new MrpRunResource($run))->toArray($request)])
            ->setStatusCode(202);
    }
}
