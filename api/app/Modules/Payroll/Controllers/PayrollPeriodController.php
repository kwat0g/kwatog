<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Requests\CreatePayrollPeriodRequest;
use App\Modules\Payroll\Requests\RunThirteenthMonthRequest;
use App\Modules\Payroll\Resources\PayrollPeriodResource;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\ThirteenthMonthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class PayrollPeriodController
{
    public function __construct(
        private readonly PayrollPeriodService $service,
        private readonly ThirteenthMonthService $thirteenthMonth,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PayrollPeriodResource::collection($this->service->list($request->query()));
    }

    /**
     * CA3 — Pipeline view: all 24 half-month slots for the year with
     * auto-schedule status and summary data.
     */
    public function pipeline(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?? now()->year);
        return response()->json(['data' => $this->service->pipeline($year)]);
    }

    public function store(CreatePayrollPeriodRequest $request): JsonResponse
    {
        $period = $this->service->create($request->validated(), $request->user());
        return (new PayrollPeriodResource($period))->response()->setStatusCode(201);
    }

    public function show(PayrollPeriod $period): PayrollPeriodResource
    {
        // Service attaches `summary` as a dynamic attribute on the period.
        return new PayrollPeriodResource($this->service->show($period));
    }

    public function compute(PayrollPeriod $period, Request $request): JsonResponse
    {
        ProcessPayrollJob::dispatch($period, $request->user()?->id);
        return (new PayrollPeriodResource($period->fresh()))
            ->response()
            ->setStatusCode(202);
    }

    public function approve(PayrollPeriod $period): PayrollPeriodResource
    {
        return new PayrollPeriodResource($this->service->approve($period));
    }

    public function finalize(PayrollPeriod $period): PayrollPeriodResource
    {
        $period = $this->service->finalize($period);
        // Dispatch GL posting job (Task 29). Wrapped in a class_exists check
        // so this controller still loads if PostPayrollToGlJob hasn't been
        // created yet.
        if (class_exists(\App\Modules\Payroll\Jobs\PostPayrollToGlJob::class)) {
            \App\Modules\Payroll\Jobs\PostPayrollToGlJob::dispatch($period);
        }
        return new PayrollPeriodResource($period);
    }

    /**
     * ADV1 — Mark a finalized period as fully disbursed.
     * Requires at least one disbursement proof to be uploaded first.
     */
    /**
     * GET /payroll-periods/{period}/variance?compare_to={hash_id}
     * Period-over-period variance report.
     */
    public function variance(Request $request, PayrollPeriod $period): JsonResponse
    {
        abort_unless($request->user()?->can('payroll.view'), 403);

        $compareToId = (string) $request->query('compare_to', '');
        if ($compareToId === '') {
            abort(422, 'compare_to parameter is required.');
        }

        $decoded = \App\Common\Support\HashIdFilter::decode($compareToId, PayrollPeriod::class);
        if (! $decoded) {
            abort(404, 'Period not found.');
        }
        $previous = PayrollPeriod::findOrFail($decoded);

        return response()->json([
            'data' => $this->service->variance($period, $previous),
        ]);
    }

    public function markDisbursed(PayrollPeriod $period, Request $request): PayrollPeriodResource
    {
        if (! class_exists(\App\Modules\Payroll\Models\DisbursementProof::class)) {
            throw new RuntimeException('DisbursementProof model not yet available.');
        }
        $user = $request->user();
        if (! $user) {
            throw new RuntimeException('Authenticated user required.');
        }
        return new PayrollPeriodResource($this->service->markDisbursed($period, $user));
    }

    /**
     * H-8 — POST /payroll-periods/{period}/force-unlock
     *
     * Admin escape hatch for periods stuck at Processing because the payroll
     * job worker crashed before its finally block ran (OOM, SIGKILL, host
     * reboot). Resets status to Draft and writes an audit log row. Service
     * rejects every other status so finalized payroll cannot be demoted.
     */
    public function forceUnlock(Request $request, PayrollPeriod $period): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->service->forceUnlock($period, $request->user(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'    => (new PayrollPeriodResource($updated))->resolve(),
            'message' => 'Period unlocked. You can re-run compute.',
        ]);
    }

    public function bankFile(PayrollPeriod $period, Request $request)
    {
        if (! class_exists(\App\Modules\Payroll\Services\BankFileService::class)) {
            return response()->json(['message' => 'Bank file service not yet available.'], 503);
        }

        $validated = $request->validate([
            'format' => ['sometimes', 'string', 'in:generic,bdo,bpi,metrobank'],
        ]);

        /** @var \App\Modules\Payroll\Services\BankFileService $svc */
        $svc = app(\App\Modules\Payroll\Services\BankFileService::class);
        return $svc->stream($period, $request->user(), $validated['format'] ?? 'generic');
    }

    /**
     * GET /payroll-periods/{period}/bank-file/preview?format=bdo
     * Returns the first 3 rows of the selected bank file format as JSON.
     */
    public function bankFilePreview(PayrollPeriod $period, Request $request): JsonResponse
    {
        if (! class_exists(\App\Modules\Payroll\Services\BankFileService::class)) {
            return response()->json(['message' => 'Bank file service not yet available.'], 503);
        }

        $validated = $request->validate([
            'format' => ['sometimes', 'string', 'in:generic,bdo,bpi,metrobank'],
        ]);

        /** @var \App\Modules\Payroll\Services\BankFileService $svc */
        $svc = app(\App\Modules\Payroll\Services\BankFileService::class);
        $preview = $svc->preview($period, $validated['format'] ?? 'generic');

        return response()->json(['data' => $preview]);
    }

    public function runThirteenthMonth(RunThirteenthMonthRequest $request): JsonResponse
    {
        if (! method_exists($this->thirteenthMonth, 'computeAndPay')) {
            return response()->json(['message' => '13th month service not yet available.'], 503);
        }
        $period = $this->thirteenthMonth->computeAndPay(
            (int) $request->validated('year'),
            $request->user(),
            $request->validated('payroll_date'),
        );
        return (new PayrollPeriodResource($period))->response()->setStatusCode(201);
    }
}
