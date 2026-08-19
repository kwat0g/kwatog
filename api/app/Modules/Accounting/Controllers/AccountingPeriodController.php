<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Requests\CloseAccountingPeriodRequest;
use App\Modules\Accounting\Requests\ReopenAccountingPeriodRequest;
use App\Modules\Accounting\Resources\AccountingPeriodResource;
use App\Modules\Accounting\Services\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountingPeriodController
{
    /**
     * Both arms narrow to BusinessRuleException, which drops one message on
     * purpose: AccountingPeriodService::assertValidMonth's "Invalid month {n};
     * expected 1-12." is now a 500. 4f40a94d annotated it as unreachable from a
     * request — CloseAccountingPeriodRequest and ReopenAccountingPeriodRequest
     * both validate `min:1|max:12` — so arriving there means a cron or seeder
     * passed a bad month, and a 422 made a caller bug look like the operator's
     * typo. The reasons they CAN act on ("Only a closed period can be
     * reopened", "A reason is required") are BusinessRuleExceptions and still
     * render as 422s.
     */
    public function __construct(private readonly AccountingPeriodService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AccountingPeriodResource::collection($this->service->list($request->query()));
    }

    public function close(CloseAccountingPeriodRequest $request): JsonResponse|AccountingPeriodResource
    {
        $data = $request->validated();
        try {
            $period = $this->service->close((int) $data['year'], (int) $data['month'], $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new AccountingPeriodResource($period->load(['closedBy', 'reopenedBy'])))
            ->response()
            ->setStatusCode(200);
    }

    public function reopen(ReopenAccountingPeriodRequest $request): JsonResponse|AccountingPeriodResource
    {
        $data = $request->validated();
        try {
            $period = $this->service->reopen(
                (int) $data['year'],
                (int) $data['month'],
                $request->user(),
                (string) $data['reason'],
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return (new AccountingPeriodResource($period->load(['closedBy', 'reopenedBy'])))
            ->response()
            ->setStatusCode(200);
    }
}
