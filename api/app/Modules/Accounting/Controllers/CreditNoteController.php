<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Requests\ApplyCreditNoteRequest;
use App\Modules\Accounting\Requests\StoreCreditNoteRequest;
use App\Modules\Accounting\Resources\CreditNoteResource;
use App\Modules\Accounting\Services\CreditNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreditNoteController
{
    public function __construct(private readonly CreditNoteService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CreditNoteResource::collection($this->service->list($request->query()));
    }

    public function show(CreditNote $creditNote): CreditNoteResource
    {
        return new CreditNoteResource($this->service->show($creditNote));
    }

    public function store(StoreCreditNoteRequest $request): JsonResponse|CreditNoteResource
    {
        try {
            $cn = $this->service->create($request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new CreditNoteResource($cn);
    }

    public function finalize(CreditNote $creditNote, Request $request): JsonResponse|CreditNoteResource
    {
        try {
            $cn = $this->service->finalize($creditNote, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new CreditNoteResource($cn);
    }

    public function apply(CreditNote $creditNote, ApplyCreditNoteRequest $request): JsonResponse|CreditNoteResource
    {
        try {
            $this->service->apply($creditNote, $request->validated(), $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new CreditNoteResource($this->service->show($creditNote->fresh()));
    }
}
