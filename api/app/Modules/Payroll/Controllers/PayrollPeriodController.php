<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\HashIdFilter;
use App\Modules\Payroll\Jobs\PostPayrollToGlJob;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\HR\Enums\EmploymentType;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Department;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Requests\CreatePayrollPeriodRequest;
use App\Modules\Payroll\Requests\RunThirteenthMonthRequest;
use App\Modules\Payroll\Requests\VoidPayrollPeriodRequest;
use App\Modules\Payroll\Resources\PayrollPeriodResource;
use App\Modules\Payroll\Services\BankFileService;
use App\Modules\Payroll\Enums\BankFileFormat;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\PayrollPeriodType;
use App\Modules\Payroll\Enums\PayrollHalfType;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\ThirteenthMonthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class PayrollPeriodController
{
    public function __construct(
        private readonly PayrollPeriodService $service,
        private readonly ThirteenthMonthService $thirteenthMonth,
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PayrollPeriodResource::collection($this->service->list($request->query()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (PayrollPeriodStatus $status): array => ['value' => $status->value, 'label' => $status->label()], PayrollPeriodStatus::cases()),
            'period_types' => array_map(
                static fn (PayrollPeriodType $type): array => ['value' => $type->value, 'label' => $type->label()],
                PayrollPeriodType::cases(),
            ),
            'half_types' => array_map(
                static fn (PayrollHalfType $type): array => ['value' => $type->value, 'label' => $type->label()],
                PayrollHalfType::cases(),
            ),
            // Scope pickers for the create form. A period may be limited to
            // employment types, pay types and/or departments; leaving all three
            // empty means company-wide.
            'employment_types' => array_map(
                static fn (EmploymentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                EmploymentType::cases(),
            ),
            'pay_types' => array_map(
                static fn (PayType $type): array => ['value' => $type->value, 'label' => $type->label()],
                PayType::cases(),
            ),
            'departments' => Department::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(static fn (Department $d): array => [
                    'value' => $d->hash_id,
                    'label' => $d->code ? "{$d->name} ({$d->code})" : $d->name,
                ])
                ->all(),
            'bank_file_formats' => array_map(static fn (BankFileFormat $format): array => ['value' => $format->value, 'label' => strtoupper($format->value === 'generic' ? 'Generic CSV' : $format->value)], BankFileFormat::cases()),
            'default_bank_file_format' => (string) $this->settings->get('payroll.bank_file.default_format', ''),
        ]]);
    }

    /**
     * POST /payroll-periods/scope-preview
     *
     * How many employees would this scope actually pay, and how many of them are
     * already claimed by another period for the same cutoff? Lets HR see the
     * blast radius BEFORE creating the period, instead of discovering an empty
     * run or a collision after Compute.
     */
    public function scopePreview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('payroll.periods.create'), 403);

        $validated = $request->validate([
            'period_start'          => ['required', 'date'],
            'period_end'            => ['required', 'date', 'after_or_equal:period_start'],
            'is_first_half'         => ['nullable', 'boolean'],
            'is_thirteenth_month'   => ['nullable', 'boolean'],
            'scope_employment_types'   => ['nullable', 'array'],
            'scope_employment_types.*' => ['string'],
            'scope_pay_types'          => ['nullable', 'array'],
            'scope_pay_types.*'        => ['string'],
            'scope_department_ids'     => ['nullable', 'array'],
            'scope_department_ids.*'   => ['string'],
        ]);

        return response()->json(['data' => $this->service->scopePreview($validated)]);
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

    /**
     * Claim the period and queue the compute run.
     *
     * The claim is synchronous (a conditional UPDATE inside
     * PayrollPeriodService::claimForCompute) so the 202 already carries
     * status=processing. Previously this dispatched blind and returned the
     * still-Draft period, so the SPA could not tell a queued run from an
     * untouched one — the Compute button stayed enabled and every extra click
     * queued another full recompute. A rejected claim is a 422 with the reason.
     */
    public function compute(PayrollPeriod $period, Request $request): JsonResponse
    {
        try {
            $claimed = $this->service->claimForCompute($period, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // claimForCompute() commits its own UPDATE (no wrapping transaction), so
        // the Processing status is already durable and the worker cannot observe
        // a pre-claim row.
        ProcessPayrollJob::dispatch($claimed, $request->user()?->id);

        return (new PayrollPeriodResource($claimed))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * REC-04 — approve is the maker-checker gate. The service rejects an actor
     * who computed this same run (unless they hold self_approve_override),
     * surfaced here as 422 so the SPA toast shows the reason cleanly.
     */
    public function approve(PayrollPeriod $period, Request $request): JsonResponse
    {
        try {
            $approved = $this->service->approve($period, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new PayrollPeriodResource($approved))->resolve(),
        ]);
    }

    public function finalize(PayrollPeriod $period, Request $request): JsonResponse
    {
        try {
            $period = $this->service->finalize($period, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Dispatch GL posting job (Task 29). Wrapped in a class_exists check
        // so this controller still loads if PostPayrollToGlJob hasn't been
        // created yet.
        if (class_exists(PostPayrollToGlJob::class)) {
            PostPayrollToGlJob::dispatch($period);
        }

        return response()->json([
            'data' => (new PayrollPeriodResource($period))->resolve(),
        ]);
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
        abort_unless($request->user()?->can('payroll.periods.view'), 403);

        $compareToId = (string) $request->query('compare_to', '');
        if ($compareToId === '') {
            abort(422, 'compare_to parameter is required.');
        }

        $decoded = HashIdFilter::decode($compareToId, PayrollPeriod::class);
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
        $user = $request->user();
        if (! $user) {
            throw new BusinessRuleException('Authenticated user required.');
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
            'data' => (new PayrollPeriodResource($updated))->resolve(),
            'message' => 'Period unlocked. You can re-run compute.',
        ]);
    }

    /**
     * REC-01 — POST /payroll-periods/{period}/void
     *
     * The one sanctioned correction for a bad *finalized* run. Delegates to
     * PayrollPeriodService::void(), which reverses any GL posting via a
     * balanced mirror JE, transitions the period to Voided, and writes an
     * audit row + PayrollPeriodVoided event. Actor is recorded as voided_by.
     * Service throws on any non-finalized status, surfaced here as 422.
     */
    public function void(VoidPayrollPeriodRequest $request, PayrollPeriod $period): JsonResponse
    {
        try {
            $voided = $this->service->void($period, $request->user(), $request->validated()['reason']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => (new PayrollPeriodResource($voided))->resolve(),
            'message' => 'Period voided. Any GL posting was reversed; you can recompute or create a replacement period.',
        ]);
    }

    public function bankFile(PayrollPeriod $period, Request $request)
    {
        $validated = $request->validate([
            'format' => ['sometimes', Rule::enum(BankFileFormat::class)],
        ]);

        /** @var BankFileService $svc */
        $svc = app(BankFileService::class);

        return $svc->stream($period, $request->user(), $validated['format'] ?? null);
    }

    public function bankFileOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'formats' => array_map(static fn (BankFileFormat $format): array => [
                'value' => $format->value,
                'label' => strtoupper($format->value === 'generic' ? 'Generic CSV' : $format->value),
            ], BankFileFormat::cases()),
            'default_format' => (string) $this->settings->get('payroll.bank_file.default_format', ''),
        ]]);
    }

    /**
     * GET /payroll-periods/{period}/bank-file/preview?format=bdo
     * Returns the first 3 rows of the selected bank file format as JSON.
     */
    public function bankFilePreview(PayrollPeriod $period, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => ['sometimes', Rule::enum(BankFileFormat::class)],
        ]);

        /** @var BankFileService $svc */
        $svc = app(BankFileService::class);
        $preview = $svc->preview($period, $validated['format'] ?? null);

        return response()->json(['data' => $preview]);
    }

    public function runThirteenthMonth(RunThirteenthMonthRequest $request): JsonResponse
    {
        $period = $this->thirteenthMonth->computeAndPay(
            (int) $request->validated('year'),
            $request->user(),
            $request->validated('payroll_date'),
        );

        return (new PayrollPeriodResource($period))->response()->setStatusCode(201);
    }
}
