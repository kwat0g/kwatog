<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\ControlledDocument;
use App\Modules\Quality\Requests\StoreControlledDocumentRequest;
use App\Modules\Quality\Requests\UpdateControlledDocumentRequest;
use App\Modules\Quality\Resources\ControlledDocumentResource;
use App\Modules\Quality\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentController
{
    public function __construct(private readonly DocumentService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ControlledDocumentResource::collection($this->service->list($request->query()));
    }

    public function show(ControlledDocument $document): ControlledDocumentResource
    {
        return new ControlledDocumentResource($this->service->show($document));
    }

    public function store(StoreControlledDocumentRequest $request): JsonResponse
    {
        $doc = $this->service->create($request->validated());
        return (new ControlledDocumentResource($doc))->response()->setStatusCode(201);
    }

    public function update(UpdateControlledDocumentRequest $request, ControlledDocument $document): ControlledDocumentResource
    {
        return new ControlledDocumentResource($this->service->update($document, $request->validated()));
    }

    public function markReviewed(ControlledDocument $document): ControlledDocumentResource
    {
        return new ControlledDocumentResource($this->service->markReviewed($document));
    }

    /**
     * Task 5 lands here — multipart upload + auto-spawn ack rows.
     */
    public function publishRevision(Request $request, ControlledDocument $document): JsonResponse
    {
        return response()->json(['message' => 'Not Implemented'], 501);
    }
}
