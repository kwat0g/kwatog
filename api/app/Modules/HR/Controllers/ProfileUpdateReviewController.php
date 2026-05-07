<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Requests\ReviewProfileUpdateRequest;
use App\Modules\HR\Resources\ProfileUpdateRequestResource;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * U3 (HR side) — review queue for employee-initiated profile changes.
 */
class ProfileUpdateReviewController
{
    public function __construct(
        private readonly ProfileUpdateRequestService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ProfileUpdateRequestResource::collection(
            $this->service->listForReview($request->query()),
        );
    }

    public function review(
        ReviewProfileUpdateRequest $request,
        ProfileUpdateRequest $profileUpdateRequest,
    ): ProfileUpdateRequestResource {
        $action = $request->validated('action');
        $remarks = $request->validated('remarks');

        $updated = $action === 'approve'
            ? $this->service->approve($profileUpdateRequest, $request->user(), $remarks)
            : $this->service->reject($profileUpdateRequest, $request->user(), $remarks);

        return new ProfileUpdateRequestResource($updated);
    }
}
