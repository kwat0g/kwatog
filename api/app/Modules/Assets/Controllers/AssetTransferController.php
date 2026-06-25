<?php

declare(strict_types=1);

namespace App\Modules\Assets\Controllers;

use App\Modules\Assets\Models\AssetTransfer;
use App\Modules\Assets\Requests\StoreAssetTransferRequest;
use App\Modules\Assets\Resources\AssetTransferResource;
use App\Modules\Assets\Services\AssetTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class AssetTransferController extends Controller
{
    public function __construct(private readonly AssetTransferService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AssetTransferResource::collection(
            $this->service->list($request->all())
        );
    }

    public function store(StoreAssetTransferRequest $request): AssetTransferResource
    {
        return new AssetTransferResource(
            $this->service->create($request->validated())
        );
    }

    public function show(AssetTransfer $assetTransfer): AssetTransferResource
    {
        return new AssetTransferResource(
            $assetTransfer->load(['asset:id,asset_code,name', 'fromDepartment:id,name', 'toDepartment:id,name'])
        );
    }

    public function approve(AssetTransfer $assetTransfer): AssetTransferResource
    {
        return new AssetTransferResource(
            $this->service->approve($assetTransfer, request()->user())
        );
    }

    public function reject(AssetTransfer $assetTransfer): JsonResponse
    {
        $this->service->reject($assetTransfer, request()->user());
        return response()->json(null, 204);
    }
}
