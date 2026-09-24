<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController
{
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
                ->lockForUpdate()
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

            return true;
        });

        if (! $terminated) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session terminated.']);
    }
}
