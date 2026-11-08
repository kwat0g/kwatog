<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Models\AuditLog;
use App\Common\Services\OutboxService;
use App\Common\Services\UserAccessRefreshService;
use App\Modules\Admin\Events\AdminSessionsChanged;
use App\Modules\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController
{
    public function __construct(
        private readonly OutboxService $outbox,
        private readonly UserAccessRefreshService $userAccessRefresh,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sessions = DB::table('sessions')
            ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
            ->select([
                'sessions.id',
                'sessions.user_id',
                'sessions.ip_address',
                'sessions.user_agent',
                'sessions.last_activity',
                'users.name as user_name',
                'users.email as user_email',
            ])
            ->whereNotNull('sessions.user_id')
            ->orderByDesc('sessions.last_activity')
            ->get()
            ->map(function ($s) use ($request) {
                $s->last_activity_at = date('Y-m-d H:i:s', (int) $s->last_activity);
                $s->is_current = $s->id === $request->session()->getId();

                return $s;
            });

        return response()->json(['data' => $sessions]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse
    {
        if ($sessionId === $request->session()->getId()) {
            return response()->json(['message' => 'Cannot terminate your own session.'], 422);
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $terminated = DB::transaction(function () use ($sessionId, $actor, $request): bool {
            $session = DB::table('sessions')
                ->leftJoin('users', 'sessions.user_id', '=', 'users.id')
                ->leftJoin('roles', 'users.role_id', '=', 'roles.id')
                ->where('sessions.id', $sessionId)
                // PostgreSQL cannot lock the nullable side of the role/user
                // LEFT JOIN; only the session row needs serialization here.
                ->lock('for update of sessions')
                ->first([
                    'sessions.id',
                    'sessions.user_id',
                    'roles.slug as role_slug',
                ]);

            if ($session === null) {
                return false;
            }

            if ($session->role_slug === 'system_admin' && $actor->role?->slug !== 'system_admin') {
                abort(403, 'Only a system administrator may terminate a system administrator session.');
            }

            if (DB::table('sessions')->where('id', $sessionId)->delete() !== 1) {
                return false;
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'action' => 'session.terminated',
                'model_type' => User::class,
                'model_id' => $session->user_id,
                'old_values' => ['session_id' => $sessionId],
                'new_values' => ['terminated_by' => $actor->hash_id],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            // The session ID is a bearer credential. Notify authorized session
            // viewers to refetch, without placing that identifier on the wire.
            $this->outbox->record(new AdminSessionsChanged(
                action: 'deleted',
                version: now()->format('Y-m-d\\TH:i:s.uP'),
                actorHashId: is_string($actor->hash_id) ? $actor->hash_id : null,
            ));

            // The revoked browser's socket is still connected until its next
            // API call. Signal that exact user so it refetches /auth/user,
            // receives 401, and clears its identity-scoped client caches.
            $this->userAccessRefresh->stageForUsers([$session->user_id]);

            return true;
        });

        if (! $terminated) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session terminated.']);
    }
}
