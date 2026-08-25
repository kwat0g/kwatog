<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Enums\ExportFormat;
use App\Common\Enums\ExportFrequency;
use App\Common\Models\ScheduledExport;
use App\Common\Resources\ScheduledExportResource;
use App\Common\Services\Export\ExportColumnRegistry;
use App\Common\Services\Export\ExportRunner;
use App\Common\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Series E (Task E2) — manage saved export schedules.
 */
class ScheduledExportController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ExportRunner $runner,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user, 401);

        $query = ScheduledExport::query()->with('owner:id,name');
        $trashed = (string) $request->query('trashed', '');
        if ($trashed === 'with') {
            $query->withTrashed();
        } elseif ($trashed === 'only') {
            $query->onlyTrashed();
        }

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

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $this->validatePayload($request);
        $defaultTime = $this->settings->requiredString('exports.default_time_of_day');
        $frequency = ExportFrequency::from($data['frequency']);
        $next = $frequency->nextRunFrom(
            now(),
            $data['day_of_week']  ?? null,
            $data['day_of_month'] ?? null,
            $data['time_of_day']  ?? $defaultTime,
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
            'time_of_day'  => $data['time_of_day']  ?? $defaultTime,
            'recipients'   => $data['recipients'],
            'next_run_at'  => $next,
            'is_active'    => true,
        ]);

        return (new ScheduledExportResource($row->load('owner:id,name')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(ScheduledExport $scheduledExport, Request $request): ScheduledExportResource
    {
        $this->authorizeRow($scheduledExport, $request);

        $data = $this->validatePayload($request, partial: true, existing: $scheduledExport);
        $scheduledExport->fill($data);
        $defaultTime = $this->settings->requiredString('exports.default_time_of_day');

        if (isset($data['frequency']) || isset($data['day_of_week']) || isset($data['day_of_month']) || isset($data['time_of_day'])) {
            $frequency = $scheduledExport->frequency instanceof ExportFrequency
                ? $scheduledExport->frequency
                : ExportFrequency::from((string) $scheduledExport->frequency);
            $scheduledExport->next_run_at = $frequency->nextRunFrom(
                now(),
                $scheduledExport->day_of_week,
                $scheduledExport->day_of_month,
                (string) ($scheduledExport->time_of_day ?? $defaultTime),
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

    public function restore(ScheduledExport $scheduledExport, Request $request): JsonResponse
    {
        $this->authorizeRow($scheduledExport, $request);
        $scheduledExport->restore();
        return response()->json(['message' => 'Scheduled export restored.']);
    }

    /** @return array<string, mixed> */
    private function validatePayload(
        Request $request,
        bool $partial = false,
        ?ScheduledExport $existing = null,
    ): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name'         => [$required, 'string', 'max:100'],
            'module'       => [$required, 'string'],
            'columns'      => [$required, 'array', 'min:1'],
            'columns.*'    => ['string'],
            'filters'      => ['nullable', 'array'],
            'format'       => ['sometimes', 'string', 'in:csv,xlsx'],
            'frequency'    => [$required, 'string', 'in:daily,weekly,monthly'],
            'day_of_week'  => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'time_of_day'  => ['nullable', 'string', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'recipients'   => [$required, 'array', 'min:1'],
            'recipients.*' => ['email'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $module = (string) ($data['module'] ?? $existing?->module ?? '');
        if (! ExportColumnRegistry::has($module)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'module' => "Module [{$module}] is not registered for export.",
            ]);
        }
        if (! $this->runner->supports($module)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'module' => "Module [{$module}] is not implemented for export.",
            ]);
        }

        $user = $request->user();
        $permission = ExportColumnRegistry::permissionFor($module);
        if ($permission !== null && ! $user->can($permission)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'module' => "Your account cannot export module [{$module}].",
            ]);
        }

        $columns = array_key_exists('columns', $data)
            ? $data['columns']
            : (array) ($existing?->columns ?? []);
        $data['columns'] = ExportColumnRegistry::validateColumns($module, $columns, $user);

        $filters = array_key_exists('filters', $data)
            ? (array) ($data['filters'] ?? [])
            : (array) ($existing?->filters ?? []);
        $data['filters'] = ExportColumnRegistry::validateFilters($module, $filters);

        // Export recipients are deliberately limited to validated email
        // addresses here. An approved-domain policy is a deployment/business
        // decision and must not be guessed from the sender address; the
        // unresolved policy remains recorded in the module fix log.
        if (array_key_exists('recipients', $data)) {
            $data['recipients'] = array_values(array_unique($data['recipients']));
        } elseif ($existing !== null) {
            $data['recipients'] = array_values(array_unique((array) $existing->recipients));
        }

        return $data;
    }

    private function authorizeRow(ScheduledExport $row, Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->can('admin.audit_logs.view')) return;
        abort_unless($row->owner_id === $user->id, 403);
    }
}
