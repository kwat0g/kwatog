<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
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

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (JournalEntryStatus $status): array => [
                'value' => $status->value,
                'label' => ucfirst($status->value),
            ], JournalEntryStatus::cases()),
        ]]);
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
        } catch (BusinessRuleException|ClosedPeriodException $e) {
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
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new JournalEntryResource($je);
    }

    public function destroy(JournalEntry $journalEntry): JsonResponse
    {
        try {
            $this->service->delete($journalEntry);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function restore(JournalEntry $journalEntry): JsonResponse
    {
        $journalEntry->restore();
        return response()->json(['message' => 'Journal entry restored.']);
    }

    public function post(Request $request, JournalEntry $journalEntry): JsonResponse|JournalEntryResource
    {
        // Three named classes, because all three carry a sentence the poster acts
        // on and none of them is a BusinessRuleException by inheritance:
        // ClosedPeriodException says which period to reopen, and
        // UnbalancedJournalEntryException gives both totals — this method has no
        // separate arm for it the way store()/update() do.
        //
        // JournalEntryService's segregation-of-duties refusal is deliberately NOT
        // listed: it is `abort(403, 'You cannot post a journal entry you
        // created...')`, so the old \RuntimeException arm relabelled a 403 as a
        // 422. It now reaches the client as the 403 it was written as, with its
        // message intact — the SPA interceptor toasts `data.message` on 403, so
        // the sentence still lands.
        try {
            $je = $this->service->post($journalEntry, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException|UnbalancedJournalEntryException $e) {
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
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new JournalEntryResource($reversal))->response()->setStatusCode(201);
    }
}
