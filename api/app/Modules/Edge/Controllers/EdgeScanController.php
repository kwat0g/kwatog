<?php

declare(strict_types=1);

namespace App\Modules\Edge\Controllers;

use App\Modules\Edge\Requests\ScanRequest;
use App\Modules\Edge\Resources\EdgeScanResultResource;
use App\Modules\Edge\Services\EdgeScanResolverService;

class EdgeScanController
{
    public function __construct(private readonly EdgeScanResolverService $resolver) {}

    public function resolve(ScanRequest $request): EdgeScanResultResource
    {
        $data = $this->resolver->resolve(
            (string) $request->validated('barcode'),
            (array) ($request->validated('context') ?? []),
        );
        return new EdgeScanResultResource($data);
    }
}
