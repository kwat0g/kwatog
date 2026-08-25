<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Requests\ListAlertsRequest;
use App\Common\Resources\AlertResource;
use App\Common\Services\AlertEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController
{
    public function __construct(private readonly AlertEngineService $engine) {}

    public function index(ListAlertsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = Alert::query()->with('entity');

        if (! empty($filters['severity'])) {
            $query->whereIn('severity', $filters['severity']);
        }
        if (! empty($filters['type'])) {
            $query->whereIn('type', $filters['type']);
        }
        if (! empty($filters['entity_type'])) {
            $query->where('entity_type', 'like', '%'.$filters['entity_type'].'%');
        }
        if (array_key_exists('is_dismissed', $filters)) {
            $val = filter_var($filters['is_dismissed'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            // The FormRequest boolean rule rejects invalid input; keep the
            // controller explicit so a future rule change cannot silently
            // turn malformed values into the unresolved view.
            abort_if($val === null, 422, 'The is_dismissed filter must be boolean.');
            $query->where('is_dismissed', $val);
            if (! $val) {
                $query->whereNull('resolved_at');
            }
        } else {
            // Default: unresolved only
            $query->whereNull('resolved_at')->where('is_dismissed', false);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q
                ->where('title', 'ilike', "%{$s}%")
                ->orWhere('message', 'ilike', "%{$s}%"));
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = min($perPage, 100);

        // Severity ordering: critical first, then warning, then info.
        $query->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 WHEN 'info' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at');

        return AlertResource::collection($query->paginate($perPage));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'types' => array_map(static fn (AlertType $type): array => ['value' => $type->value, 'label' => $type->label()], AlertType::cases()),
            'severities' => array_map(static fn (AlertSeverity $severity): array => ['value' => $severity->value, 'label' => $severity->label()], AlertSeverity::cases()),
        ]]);
    }

    public function dismiss(Alert $alert): AlertResource
    {
        abort_unless(auth()->user()?->can('alerts.dismiss'), 403);
        $alert = $this->engine->dismiss($alert, auth()->user());

        return new AlertResource($alert);
    }

    public function markRead(Alert $alert): AlertResource
    {
        abort_unless(auth()->user()?->can('alerts.view'), 403);
        $alert = $this->engine->markRead($alert);

        return new AlertResource($alert);
    }

    public function unreadCount(): JsonResponse
    {
        abort_unless(auth()->user()?->can('alerts.view'), 403);
        $count = Alert::whereNull('resolved_at')
            ->where('is_dismissed', false)
            ->where('is_read', false)
            ->count();

        return response()->json(['data' => ['count' => $count]]);
    }
}
