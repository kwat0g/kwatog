<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Enums\PpapElementStatus;
use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\Quality\Requests\StorePpapRequest;
use App\Modules\Quality\Resources\PpapElementResource;
use App\Modules\Quality\Resources\PpapSubmissionResource;
use App\Modules\Quality\Services\PpapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

class PpapController
{
    public function __construct(private readonly PpapService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PpapSubmissionResource::collection($this->service->list($request->query()));
    }

    public function show(PpapSubmission $ppap): PpapSubmissionResource
    {
        return new PpapSubmissionResource($this->service->show($ppap));
    }

    public function store(StorePpapRequest $request): JsonResponse
    {
        $ppap = $this->service->create($request->validatedData(), $request->user());
        return (new PpapSubmissionResource($ppap))->response()->setStatusCode(201);
    }

    public function update(Request $request, PpapSubmission $ppap): PpapSubmissionResource
    {
        $data = $request->validate([
            'ppap_level' => ['sometimes', 'string'],
            'product_id' => ['sometimes', 'nullable', 'integer'],
            'notes'      => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        try {
            return new PpapSubmissionResource($this->service->update($ppap, $data));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function submit(Request $request, PpapSubmission $ppap): PpapSubmissionResource
    {
        try {
            return new PpapSubmissionResource($this->service->submit($ppap));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function review(Request $request, PpapSubmission $ppap): PpapSubmissionResource
    {
        try {
            return new PpapSubmissionResource($this->service->review($ppap, $request->user()));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function approve(Request $request, PpapSubmission $ppap): PpapSubmissionResource
    {
        try {
            return new PpapSubmissionResource($this->service->approve($ppap, $request->user()));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function reject(Request $request, PpapSubmission $ppap): PpapSubmissionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            return new PpapSubmissionResource($this->service->reject($ppap, $data['reason'], $request->user()));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function updateElement(Request $request, PpapSubmission $ppap, PpapElement $element): PpapElementResource
    {
        abort_unless($element->ppap_submission_id === $ppap->id, 404);
        $data = $request->validate([
            'status'        => ['sometimes', Rule::in(PpapElementStatus::values())],
            'document_path' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        return new PpapElementResource($this->service->updateElement($element, $data));
    }
}
