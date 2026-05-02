<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Requests\ReverseJournalEntryRequest;
use App\Modules\Accounting\Requests\StoreJournalEntryRequest;
use App\Modules\Accounting\Requests\UpdateJournalEntryRequest;
use App\Modules\Accounting\Resources\JournalEntryResource;
use App\Modules\Accounting\Services\JournalEntryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalEntryController
{
    public function __construct(private readonly JournalEntryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return JournalEntryResource::collection($this->service->list($request->query()));
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($this->service->show($journalEntry));
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        try {
            $je = $this->service->create($request->validated(), $request->user());
        } catch (UnbalancedJournalEntryException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => ['lines' => [$e->getMessage()]],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new JournalEntryResource($je))->response()->setStatusCode(201);
    }

    public function update(UpdateJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse|JournalEntryResource
    {
        try {
            $je = $this->service->update($journalEntry, $request->validated(), $request->user());
        } catch (UnbalancedJournalEntryException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors'  => ['lines' => [$e->getMessage()]],
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new JournalEntryResource($je);
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        try {
            $this->service->delete($journalEntry);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function post(Request $request, JournalEntry $journalEntry): JsonResponse|JournalEntryResource
    {
        try {
            $je = $this->service->post($journalEntry, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new JournalEntryResource($je);
    }

    public function reverse(ReverseJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse|JournalEntryResource
    {
        try {
            $reversal = $this->service->reverse(
                $journalEntry,
                $request->user(),
                $request->filled('reverse_date') ? Carbon::parse($request->input('reverse_date')) : null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new JournalEntryResource($reversal))->response()->setStatusCode(201);
    }
}
