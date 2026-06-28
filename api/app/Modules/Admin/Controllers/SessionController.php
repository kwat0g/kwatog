<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

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

        $deleted = DB::table('sessions')->where('id', $sessionId)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        return response()->json(['message' => 'Session terminated.']);
    }
}
