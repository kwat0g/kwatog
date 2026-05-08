<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Requests\CreateNcrRequest;
use App\Modules\Quality\Resources\NcrActionResource;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\NcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class NcrController
{
    public function __construct(private readonly NcrService $service) {}

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
                            $ncr->disposition?->value ?? 'rework',
                            $ncr->root_cause,
                            $note,
                        );
                    }
                    $this->service->close($ncr->fresh(), $user);
                });
                $results[] = ['ncr_id' => $hashId, 'status' => 'success', 'message' => 'Closed.'];
                $success++;
            } catch (Throwable $e) {
                // Already closed or no disposition → skip rather than fail.
                $msg = $e->getMessage();
                if (str_contains($msg, 'already closed') || str_contains($msg, 'without a disposition')) {
                    $results[] = ['ncr_id' => $hashId, 'status' => 'skipped', 'message' => $msg];
                    $skipped++;
                } else {
                    $results[] = ['ncr_id' => $hashId, 'status' => 'failed', 'message' => $msg];
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
}
