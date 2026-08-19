<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Requests\CreateNcrRequest;
use App\Modules\Quality\Resources\NcrActionResource;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\NcrService;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class NcrController
{
    public function __construct(
        private readonly NcrService $service,
        private readonly SettingsService $settings,
    ) {}

    public function options(): JsonResponse
    {
        $label = static fn (string $value): string => ucwords(str_replace('_', ' ', $value));
        $map = static fn (array $cases) => array_map(
            fn ($case) => ['value' => $case->value, 'label' => method_exists($case, 'label') ? $case->label() : $label($case->value)],
            $cases,
        );
        return response()->json(['data' => [
            'sources' => $map(NcrSource::cases()),
            'severities' => $map(NcrSeverity::cases()),
            'statuses' => $map(NcrStatus::cases()),
            'actions' => $map(NcrActionType::cases()),
            'dispositions' => $map(NcrDisposition::cases()),
            'escalation_roles' => array_values(array_filter((array) $this->settings->get('quality.ncr.escalation_roles', []), 'is_string')),
            'default_disposition' => (string) $this->settings->get('quality.ncr.default_disposition', ''),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return NcrResource::collection($this->service->list($request->query()));
    }

    public function show(NonConformanceReport $ncr): NcrResource
    {
        return new NcrResource($this->service->show($ncr));
    }

    public function store(CreateNcrRequest $request): NcrResource
    {
        return new NcrResource($this->service->create($request->validated(), $request->user()));
    }

    public function addAction(Request $request, NonConformanceReport $ncr): NcrActionResource
    {
        $request->validate([
            'action_type'  => ['required', Rule::in(NcrActionType::values())],
            'description'  => ['required', 'string', 'max:5000'],
            'performed_at' => ['nullable', 'date'],
        ]);
        $action = $this->service->addAction($ncr, $request->only(['action_type', 'description', 'performed_at']), $request->user());
        return new NcrActionResource($action);
    }

    public function setDisposition(Request $request, NonConformanceReport $ncr): NcrResource
    {
        $data = $request->validate([
            'disposition'       => ['required', Rule::in(NcrDisposition::values())],
            'root_cause'        => ['nullable', 'string', 'max:5000'],
            'corrective_action' => ['nullable', 'string', 'max:5000'],
        ]);
        $ncr = $this->service->setDisposition(
            $ncr,
            (string) $data['disposition'],
            $data['root_cause']        ?? null,
            $data['corrective_action'] ?? null,
        );
        return new NcrResource($ncr);
    }

    public function close(Request $request, NonConformanceReport $ncr): NcrResource
    {
        return new NcrResource($this->service->close($ncr, $request->user()));
    }

    public function cancel(Request $request, NonConformanceReport $ncr): NcrResource
    {
        $reason = $request->input('reason');
        return new NcrResource($this->service->cancel($ncr, is_string($reason) ? $reason : null, $request->user()));
    }

    /**
     * Series F — Task F6. Bulk close NCRs.
     *
     * POST /api/v1/quality/ncrs/bulk-close
     * Body: { ncr_ids: string[] (hash IDs), resolution_note?: string }
     *
     * Returns 207 Multi-Status when any individual NCR fails to close
     * (e.g. because it has no disposition); the response body lists each
     * outcome so the SPA can render a per-id summary.
     */
    public function bulkClose(Request $request): JsonResponse
    {
        $request->validate([
            'ncr_ids'         => ['required', 'array', 'min:1', 'max:200'],
            'ncr_ids.*'       => ['required', 'string'],
            'resolution_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $hashids = app('hashids');
        $user = $request->user();
        $note = $request->input('resolution_note');

        $results = [];
        $success = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ((array) $request->input('ncr_ids') as $hashId) {
            $decoded = $hashids->decode((string) $hashId);
            if (empty($decoded)) {
                $results[] = ['ncr_id' => $hashId, 'status' => 'failed', 'message' => 'Invalid ID.'];
                $failed++;
                continue;
            }

            $ncr = NonConformanceReport::find((int) $decoded[0]);
            if (! $ncr) {
                $results[] = ['ncr_id' => $hashId, 'status' => 'failed', 'message' => 'Not found.'];
                $failed++;
                continue;
            }

            try {
                DB::transaction(function () use ($ncr, $user, $note) {
                    if (is_string($note) && $note !== '' && $ncr->corrective_action === null) {
                        $this->service->setDisposition(
                            $ncr,
                            $ncr->disposition?->value ?? (string) $this->settings->get('quality.ncr.default_disposition', ''),
                            $ncr->root_cause,
                            $note,
                        );
                    }
                    $this->service->close($ncr->fresh(), $user);
                });
                $results[] = ['ncr_id' => $hashId, 'status' => 'success', 'message' => 'Closed.'];
                $success++;
            } catch (Throwable $e) {
                $reason = $this->bulkCloseFailureReason((int) $decoded[0], $e);

                // Already closed or no disposition → skip rather than fail. Both
                // sentences come from NcrService as a BusinessRuleException, and
                // only that class reaches here with its own wording, so the match
                // can no longer be satisfied by an incidental substring in an
                // exception nobody authored.
                if ($e instanceof BusinessRuleException
                    && (str_contains($reason, 'already closed') || str_contains($reason, 'without a disposition'))) {
                    $results[] = ['ncr_id' => $hashId, 'status' => 'skipped', 'message' => $reason];
                    $skipped++;
                } else {
                    $results[] = ['ncr_id' => $hashId, 'status' => 'failed', 'message' => $reason];
                    $failed++;
                }
            }
        }

        $status = $failed > 0 ? 207 : 200;

        return response()->json([
            'data' => [
                'summary' => [
                    'total'   => count($results),
                    'success' => $success,
                    'skipped' => $skipped,
                    'failed'  => $failed,
                ],
                'results' => $results,
            ],
        ], $status);
    }

    /**
     * The per-row `message` this endpoint returns is rendered verbatim by the
     * SPA, so it must never carry an exception nobody wrote for a reader.
     * `catch (Throwable)` here previously put `$e->getMessage()` straight into
     * it, which meant a unique-constraint violation or a deadlock inside the
     * close transaction showed the quality engineer
     * `SQLSTATE[23505]: ... (Connection: pgsql, SQL: insert into "ncr_actions" ...)`.
     * Same defect, same shape, and the same fix as LeaveRequestService's bulk
     * approve (2b82cba8 / f54822f7).
     *
     * Three arms, in order of how much the text was written to be read:
     *  1. BusinessRuleException — authored copy, and the only thing that tells
     *     the user what to do about the row ("Cannot close NCR without a
     *     disposition.").
     *  2. a 4xx HttpException with a message — `abort(403, '...')` states a
     *     refusal on purpose; it is authored copy in everything but its type.
     *  3. anything else — fixed copy, plus a log line carrying the class and
     *     message so support can still find it.
     */
    private function bulkCloseFailureReason(int $ncrId, Throwable $e): string
    {
        if ($e instanceof BusinessRuleException) {
            return $e->getMessage();
        }

        if ($e instanceof HttpExceptionInterface) {
            $status  = $e->getStatusCode();
            $message = trim($e->getMessage());
            if ($status >= 400 && $status < 500 && $message !== '') {
                return $message;
            }
        }

        Log::error('NcrController::bulkClose — unexpected failure closing an NCR.', [
            'ncr_id'    => $ncrId,
            'exception' => $e::class,
            'message'   => $e->getMessage(),
        ]);

        return 'An unexpected error stopped this NCR from closing.';
    }
}
