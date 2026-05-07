<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Enums\ExportFormat;
use App\Common\Enums\ExportFrequency;
use App\Common\Models\ScheduledExport;
use App\Common\Resources\ScheduledExportResource;
use App\Common\Services\Export\ExportColumnRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Series E (Task E2) — manage saved export schedules.
 */
class ScheduledExportController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user, 401);

        $query = ScheduledExport::query()->with('owner:id,name');

        // Non-admins only see their own.
        if (! $user->can('admin.audit_logs.view')) {
            $query->where('owner_id', $user->id);
        }

        return ScheduledExportResource::collection(
            $query->orderByDesc('created_at')->paginate(25),
        );
    }

    public function show(ScheduledExport $scheduledExport, Request $request): ScheduledExportResource
    {
        $this->authorizeRow($scheduledExport, $request);
        return new ScheduledExportResource($scheduledExport->load('owner:id,name'));
    }

    public function store(Request $request): ScheduledExportResource
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $this->validatePayload($request);
        $frequency = ExportFrequency::from($data['frequency']);
        $next = $frequency->nextRunFrom(
            now(),
            $data['day_of_week']  ?? null,
            $data['day_of_month'] ?? null,
            $data['time_of_day']  ?? '06:00',
        );

        $row = ScheduledExport::create([
            'owner_id'     => $user->id,
            'name'         => $data['name'],
            'module'       => $data['module'],
            'columns'      => $data['columns'],
            'filters'      => $data['filters'] ?? [],
            'format'       => $data['format'] ?? ExportFormat::Xlsx->value,
            'frequency'    => $frequency->value,
            'day_of_week'  => $data['day_of_week']  ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'time_of_day'  => $data['time_of_day']  ?? '06:00',
            'recipients'   => $data['recipients'],
            'next_run_at'  => $next,
            'is_active'    => true,
        ]);

        return new ScheduledExportResource($row->load('owner:id,name'));
    }

    public function update(ScheduledExport $scheduledExport, Request $request): ScheduledExportResource
    {
        $this->authorizeRow($scheduledExport, $request);

        $data = $this->validatePayload($request, partial: true);
        $scheduledExport->fill($data);

        if (isset($data['frequency']) || isset($data['day_of_week']) || isset($data['day_of_month']) || isset($data['time_of_day'])) {
            $frequency = $scheduledExport->frequency instanceof ExportFrequency
                ? $scheduledExport->frequency
                : ExportFrequency::from((string) $scheduledExport->frequency);
            $scheduledExport->next_run_at = $frequency->nextRunFrom(
                now(),
                $scheduledExport->day_of_week,
                $scheduledExport->day_of_month,
                (string) ($scheduledExport->time_of_day ?? '06:00'),
            );
        }

        $scheduledExport->save();
        return new ScheduledExportResource($scheduledExport->fresh()->load('owner:id,name'));
    }

    public function destroy(ScheduledExport $scheduledExport, Request $request): JsonResponse
    {
        $this->authorizeRow($scheduledExport, $request);
        $scheduledExport->delete();
        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name'         => [$required, 'string', 'max:100'],
            'module'       => [$required, 'string', function ($attr, $value, $fail) {
                if (! ExportColumnRegistry::has((string) $value)) {
                    $fail("Module [{$value}] is not registered for export.");
                }
            }],
            'columns'      => [$required, 'array', 'min:1'],
            'columns.*'    => ['string'],
            'filters'      => ['nullable', 'array'],
            'format'       => ['sometimes', 'string', 'in:csv,xlsx'],
            'frequency'    => [$required, 'string', 'in:daily,weekly,monthly'],
            'day_of_week'  => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'time_of_day'  => ['nullable', 'string', 'regex:/^[0-2][0-9]:[0-5][0-9]$/'],
            'recipients'   => [$required, 'array', 'min:1'],
            'recipients.*' => ['email'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeRow(ScheduledExport $row, Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->can('admin.audit_logs.view')) return;
        abort_unless($row->owner_id === $user->id, 403);
    }
}
