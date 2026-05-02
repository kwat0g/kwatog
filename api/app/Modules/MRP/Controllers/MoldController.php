<?php

declare(strict_types=1);

namespace App\Modules\MRP\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Requests\AssignMoldCompatibilityRequest;
use App\Modules\MRP\Requests\StoreMoldRequest;
use App\Modules\MRP\Requests\UpdateMoldRequest;
use App\Modules\MRP\Resources\MoldHistoryResource;
use App\Modules\MRP\Resources\MoldResource;
use App\Modules\MRP\Services\MoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MoldController
{
    public function __construct(private readonly MoldService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MoldResource::collection($this->service->list($request->query()));
    }

    public function show(Mold $mold): MoldResource
    {
        return new MoldResource($this->service->show($mold));
    }

    public function store(StoreMoldRequest $request): JsonResponse
    {
        $payload = $request->validated();
        // Sane default for output_rate_per_hour if caller did not provide it.
        if (empty($payload['output_rate_per_hour']) && ! empty($payload['cycle_time_seconds']) && ! empty($payload['cavity_count'])) {
            $payload['output_rate_per_hour'] = (int) floor(3600 / (int) $payload['cycle_time_seconds'] * (int) $payload['cavity_count']);
        }
        $m = $this->service->create($payload);
        return (new MoldResource($m))->response()->setStatusCode(201);
    }

    public function update(UpdateMoldRequest $request, Mold $mold): MoldResource
    {
        return new MoldResource($this->service->update($mold, $request->validated()));
    }

    public function destroy(Mold $mold): JsonResponse
    {
        $this->service->delete($mold);
        return response()->json(null, 204);
    }

    public function syncCompatibility(AssignMoldCompatibilityRequest $request, Mold $mold): MoldResource
    {
        $m = $this->service->syncCompatibility($mold, $request->validated()['machine_ids']);
        return new MoldResource($m);
    }

    public function history(Mold $mold): AnonymousResourceCollection
    {
        return MoldHistoryResource::collection(
            $mold->history()->orderByDesc('event_date')->orderByDesc('created_at')->get()
        );
    }

    public function byProduct(Product $product): AnonymousResourceCollection
    {
        return MoldResource::collection(
            Mold::with('compatibleMachines:id,machine_code,name,tonnage')
                ->withCount('compatibleMachines')
                ->where('product_id', $product->id)
                ->orderBy('mold_code')
                ->get()
        );
    }
}
