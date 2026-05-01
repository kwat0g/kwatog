<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Models\AuditLog;
use App\Modules\Admin\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AuditLog::query()->with('user')->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }
        if ($request->filled('model_type')) {
            $query->where('model_type', 'ilike', '%'.$request->string('model_type').'%');
        }
        if ($request->filled('user_id')) {
            // Accept either an integer id (admin tooling) or a hashid (UI).
            $raw = $request->string('user_id')->toString();
            $userId = ctype_digit($raw)
                ? (int) $raw
                : \App\Modules\Auth\Models\User::tryDecodeHash($raw);
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to'));
        }

        $perPage = min((int) ($request->integer('per_page') ?: 25), 100);

        return AuditLogResource::collection($query->paginate($perPage));
    }
}
