<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Services\ActivityFeedService;
use App\Modules\Admin\Enums\ActivityType;
use App\Modules\Admin\Enums\ActivitySeverity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Series F — Task F7. Admin activity feed.
 *
 * GET /api/v1/admin/activity?type=&severity=&from=&to=&search=&page=&per_page=
 */
class ActivityFeedController
{
    public function __construct(private readonly ActivityFeedService $service) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'types' => array_map(static fn (ActivityType $type): array => ['value' => $type->value, 'label' => $type->label()], ActivityType::cases()),
            'severities' => array_map(static fn (ActivitySeverity $severity): array => ['value' => $severity->value, 'label' => $severity->label()], ActivitySeverity::cases()),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'type'      => ['nullable', 'string', 'max:30'],
            'severity'  => ['nullable', \Illuminate\Validation\Rule::enum(ActivitySeverity::class)],
            'actor_user_id' => ['nullable', 'string'],
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date', 'after_or_equal:from'],
            'search'    => ['nullable', 'string', 'max:120'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $page = $this->service->feed($request->query());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($e) => [
                'id'           => $e->hash_id,
                'type'         => $e->type,
                'type_label'   => ActivityType::tryFrom((string) $e->type)?->label() ?? ucfirst((string) $e->type),
                'action'       => $e->action,
                'actor'        => $e->actor ? [
                    'id'    => app('hashids')->encode($e->actor->id),
                    'name'  => $e->actor->name,
                    'email' => $e->actor->email,
                    // ADV4 — role chip alongside actor name.
                    'role'  => $e->actor->role ? [
                        'name' => $e->actor->role->name,
                        'slug' => $e->actor->role->slug,
                    ] : null,
                ] : null,
                'actor_type'   => $e->actor_type,
                'subject_type' => $e->subject_type,
                'subject_id'   => $e->subject_id ? app('hashids')->encode($e->subject_id) : null,
                'summary'      => $e->summary,
                'detail'       => $e->detail,
                'link'         => $e->link,
                'severity'     => $e->severity,
                'created_at'   => $e->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ]);
    }
}
