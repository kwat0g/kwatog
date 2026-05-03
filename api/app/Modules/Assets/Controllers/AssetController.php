<?php

declare(strict_types=1);

namespace App\Modules\Assets\Controllers;

use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Requests\DisposeAssetRequest;
use App\Modules\Assets\Requests\StoreAssetRequest;
use App\Modules\Assets\Requests\UpdateAssetRequest;
use App\Modules\Assets\Resources\AssetResource;
use App\Modules\Assets\Services\AssetQrCodeService;
use App\Modules\Assets\Services\AssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController
{
    public function __construct(
        private readonly AssetService $service,
        private readonly AssetQrCodeService $qr,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AssetResource::collection($this->service->list($request->query()));
    }

    public function show(Asset $asset): AssetResource
    {
        return new AssetResource($this->service->show($asset));
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = $this->service->create($request->validated());
        return (new AssetResource($asset))->response()->setStatusCode(201);
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        return new AssetResource($this->service->update($asset, $request->validated()));
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $this->service->delete($asset);
        return response()->json(null, 204);
    }

    public function dispose(DisposeAssetRequest $request, Asset $asset): AssetResource
    {
        return new AssetResource($this->service->dispose($asset, $request->validated(), $request->user()));
    }

    public function qrPayload(Asset $asset): JsonResponse
    {
        return response()->json(['data' => $this->qr->payload($asset)]);
    }
}
