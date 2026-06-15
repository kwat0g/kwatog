<?php

declare(strict_types=1);

namespace App\Modules\Edge\Controllers;

use App\Modules\Edge\Models\EdgeDevice;
use App\Modules\Edge\Requests\IssueTokenRequest;
use App\Modules\Edge\Requests\StoreEdgeDeviceRequest;
use App\Modules\Edge\Requests\UpdateEdgeDeviceRequest;
use App\Modules\Edge\Resources\EdgeDeviceResource;
use App\Modules\Edge\Services\EdgeDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeDeviceAdminController
{
    public function __construct(private readonly EdgeDeviceService $service) {}

    public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return EdgeDeviceResource::collection($this->service->list($request->all()));
    }

    public function store(StoreEdgeDeviceRequest $request): EdgeDeviceResource
    {
        return new EdgeDeviceResource($this->service->create($request->validated()));
    }

    public function show(EdgeDevice $edgeDevice): EdgeDeviceResource
    {
        return new EdgeDeviceResource($edgeDevice);
    }

    public function update(UpdateEdgeDeviceRequest $request, EdgeDevice $edgeDevice): EdgeDeviceResource
    {
        return new EdgeDeviceResource($this->service->update($edgeDevice, $request->validated()));
    }

    public function deactivate(EdgeDevice $edgeDevice): EdgeDeviceResource
    {
        return new EdgeDeviceResource($this->service->deactivate($edgeDevice));
    }

    /**
     * Issue a new bearer token. Plaintext token returned ONCE — caller must
     * capture immediately, no recovery.
     */
    public function issueToken(IssueTokenRequest $request, EdgeDevice $edgeDevice): JsonResponse
    {
        $expires = $request->input('expires_at') ? new \DateTimeImmutable($request->input('expires_at')) : null;
        $token = $this->service->issueToken($edgeDevice, $request->validated('name'), $expires);

        return response()->json([
            'data' => [
                'plaintext_token' => $token->plainTextToken,
                'abilities'       => $token->accessToken->abilities,
                'expires_at'      => optional($token->accessToken->expires_at)->toIso8601String(),
            ],
        ], 201);
    }

    public function revokeTokens(EdgeDevice $edgeDevice): JsonResponse
    {
        $count = $this->service->revokeAllTokens($edgeDevice);
        return response()->json(['data' => ['revoked' => $count]]);
    }
}
