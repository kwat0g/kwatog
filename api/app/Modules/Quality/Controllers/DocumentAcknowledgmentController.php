<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\DocumentRevision;
use App\Modules\Quality\Resources\DocumentAcknowledgmentResource;
use App\Modules\Quality\Services\DocumentAcknowledgmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * T3.5.C — Self-service controller for document acknowledgments.
 *
 * Mounted at /api/v1/self-service/documents/* — auth:sanctum only; the
 * service enforces ownership (no cross-user 200s). Route binding for
 * {revision} resolves through HasHashId.
 */
class DocumentAcknowledgmentController
{
    public function __construct(private readonly DocumentAcknowledgmentService $service) {}

    public function pending(Request $request): AnonymousResourceCollection
    {
        return DocumentAcknowledgmentResource::collection(
            $this->service->pending($request->user())
        );
    }

    public function acknowledge(Request $request, DocumentRevision $revision): DocumentAcknowledgmentResource
    {
        return new DocumentAcknowledgmentResource(
            $this->service->acknowledgeForRevision($revision, $request->user())
        );
    }
}
