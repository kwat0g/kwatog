<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Concerns\DashboardQueries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Administrator dashboard — system health and security monitoring.
 *
 * Shows no business KPIs. Focused on: active sessions, account security
 * (locked/inactive accounts), authentication events, job queue health,
 * recent audit trail, and open system alerts.
 */
class AdminDashboardService
{
    use DashboardQueries;

    private const CACHE_TTL = 30;

    public function admin(User $user): array
    {
        return Cache::remember("dashboard:admin:{$user->id}", self::CACHE_TTL, function () {
            return [
                'kpis'   => $this->systemKpis(),
                'panels' => [
                    'active_sessions'   => $this->activeSessions(),
                    'account_security'  => $this->accountSecurity(),
                    'auth_events'       => $this->authEvents(),
                    'queue_health'      => $this->queueHealth(),
                    'recent_audit'      => $this->recentAuditEvents(),
                    'open_alerts'       => $this->openSystemAlerts(),
                ],
            ];
        });
    }

    // ── KPIs ────────────────────────────────────────────────────────────────

    private function systemKpis(): array
    {
        // Active sessions in last 30 minutes
        $activeSessions = $this->safeCount('sessions', fn ($q) =>
            $q->where('last_activity', '>=', now()->subMinutes(30)->timestamp)
        );

        // Locked accounts right now
        $lockedAccounts = $this->safeCount('users', fn ($q) =>
            $q->whereNotNull('locked_until')
              ->where('locked_until', '>', now())
              ->whereNull('deleted_at')
        );

        // Failed login attempts in last 24h
        $failedLogins = $this->safeCount('login_history', fn ($q) =>
            $q->whereIn('status', ['failed_credentials', 'failed_locked', 'failed_inactive', 'failed_password_expired'])
              ->where('created_at', '>=', now()->subHours(24))
        );

        // Failed background jobs
        $failedJobs = $this->safeCount('failed_jobs');

        return [
            $this->kpi('Active Sessions',     (string) $activeSessions, 'sessions'),
            $this->kpi('Locked Accounts',     (string) $lockedAccounts, 'accounts'),
            $this->kpi('Failed Logins (24h)', (string) $failedLogins,   'attempts'),
            $this->kpi('Failed Jobs',         (string) $failedJobs,     'jobs'),
        ];
    }

    // ── Active Sessions ──────────────────────────────────────────────────────

    private function activeSessions(): array
    {
        if (! Schema::hasTable('sessions') || ! Schema::hasTable('users')) {
            return ['sessions' => [], 'total' => 0, 'unique_users' => 0];
        }

        $cutoff = now()->subMinutes(30)->timestamp;

        $rows = DB::table('sessions as s')
            ->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->where('s.last_activity', '>=', $cutoff)
            ->orderByDesc('s.last_activity')
            ->limit(15)
            ->select([
                'u.name as user_name',
                'r.name as role_name',
                's.ip_address',
                's.user_agent',
                's.last_activity',
            ])
            ->get();

        $total       = DB::table('sessions')->where('last_activity', '>=', $cutoff)->count();
        $uniqueUsers = DB::table('sessions')->where('last_activity', '>=', $cutoff)->whereNotNull('user_id')->distinct('user_id')->count('user_id');

        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = [
                'user'          => $row->user_name ?? 'Guest',
                'role'          => $row->role_name ?? '—',
                'ip'            => $row->ip_address ?? '—',
                'device'        => $this->parseDevice($row->user_agent ?? ''),
                'last_activity' => Carbon::createFromTimestamp($row->last_activity)->toISOString(),
            ];
        }

        return [
            'sessions'     => $sessions,
            'total'        => (int) $total,
            'unique_users' => (int) $uniqueUsers,
        ];
    }

    // ── Account Security ─────────────────────────────────────────────────────

    private function accountSecurity(): array
    {
        if (! Schema::hasTable('users')) {
            return [
                'total' => 0, 'active' => 0, 'inactive' => 0,
                'locked' => 0, 'must_change_password' => 0,
                'locked_accounts' => [],
            ];
        }

        $now = now();

        $total    = DB::table('users')->whereNull('deleted_at')->count();
        $active   = DB::table('users')->whereNull('deleted_at')->where('is_active', true)->count();
        $inactive = DB::table('users')->whereNull('deleted_at')->where('is_active', false)->count();
        $locked   = DB::table('users')->whereNull('deleted_at')
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', $now)
            ->count();
        $mustChange = DB::table('users')->whereNull('deleted_at')
            ->where('must_change_password', true)
            ->count();

        // Accounts with high failed attempt count (>= 3 but not yet locked)
        $atRisk = DB::table('users')->whereNull('deleted_at')
            ->where('failed_login_attempts', '>=', 3)
            ->where(function ($q) use ($now) {
                $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now);
            })
            ->count();

        // Currently locked account details
        $lockedRows = DB::table('users as u')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->whereNull('u.deleted_at')
            ->whereNotNull('u.locked_until')
            ->where('u.locked_until', '>', $now)
            ->orderBy('u.locked_until')
            ->limit(10)
            ->select(['u.name', 'u.email', 'r.name as role_name', 'u.failed_login_attempts', 'u.locked_until'])
            ->get();

        $lockedAccounts = [];
        foreach ($lockedRows as $row) {
            $lockedAccounts[] = [
                'name'     => $row->name,
                'email'    => $row->email,
                'role'     => $row->role_name ?? '—',
                'attempts' => (int) $row->failed_login_attempts,
                'locked_until' => $row->locked_until,
            ];
        }

        return [
            'total'               => (int) $total,
            'active'              => (int) $active,
            'inactive'            => (int) $inactive,
            'locked'              => (int) $locked,
            'at_risk'             => (int) $atRisk,
            'must_change_password'=> (int) $mustChange,
            'locked_accounts'     => $lockedAccounts,
        ];
    }

    // ── Authentication Events ────────────────────────────────────────────────

    private function authEvents(): array
    {
        // 24h breakdown by status
        $breakdown = [];
        if (Schema::hasTable('login_history')) {
            $rows = DB::table('login_history')
                ->where('created_at', '>=', now()->subHours(24))
                ->select('status', DB::raw('COUNT(*) as total'))
                ->groupBy('status')
                ->get();

            foreach ($rows as $row) {
                $breakdown[$row->status] = (int) $row->total;
            }
        }

        // 24h hourly trend (successful logins)
        $successTrend = [];
        if (Schema::hasTable('login_history')) {
            $hourRows = DB::table('login_history')
                ->where('status', 'success')
                ->where('created_at', '>=', now()->subHours(23)->startOfHour())
                ->selectRaw("date_trunc('hour', created_at) as hour, COUNT(*) as total")
                ->groupByRaw("date_trunc('hour', created_at)")
                ->pluck('total', 'hour')
                ->toArray();

            for ($i = 23; $i >= 0; $i--) {
                $hour = now()->subHours($i)->startOfHour()->toISOString();
                $successTrend[] = (int) ($hourRows[$hour] ?? 0);
            }
        }

        // Recent failed attempts (last 20)
        $recentFailed = [];
        if (Schema::hasTable('login_history')) {
            $rows = DB::table('login_history as lh')
                ->leftJoin('users as u', 'u.id', '=', 'lh.user_id')
                ->whereIn('lh.status', ['failed_credentials', 'failed_locked', 'failed_inactive', 'failed_password_expired'])
                ->where('lh.created_at', '>=', now()->subHours(24))
                ->orderByDesc('lh.created_at')
                ->limit(20)
                ->select(['lh.email_attempted', 'lh.status', 'lh.reason', 'lh.ip_address', 'lh.created_at'])
                ->get();

            foreach ($rows as $row) {
                $recentFailed[] = [
                    'email'      => $row->email_attempted ?? '—',
                    'status'     => $row->status,
                    'reason'     => $row->reason ?? '—',
                    'ip'         => $row->ip_address ?? '—',
                    'created_at' => $row->created_at,
                ];
            }
        }

        return [
            'breakdown_24h'   => $breakdown,
            'success_trend_24h' => $successTrend,
            'recent_failures' => $recentFailed,
        ];
    }

    // ── Queue Health ─────────────────────────────────────────────────────────

    private function queueHealth(): array
    {
        $pendingJobs = $this->safeCount('jobs');
        $failedJobs  = $this->safeCount('failed_jobs');

        // Recent failed jobs (last 10)
        $recentFailed = [];
        if (Schema::hasTable('failed_jobs')) {
            $rows = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(10)
                ->select(['uuid', 'queue', 'failed_at',
                    DB::raw("LEFT(exception, 200) as exception_preview")])
                ->get();

            foreach ($rows as $row) {
                $recentFailed[] = [
                    'uuid'      => $row->uuid,
                    'queue'     => $row->queue,
                    'error'     => $row->exception_preview ?? '—',
                    'failed_at' => $row->failed_at,
                ];
            }
        }

        return [
            'pending_jobs'   => $pendingJobs,
            'failed_jobs'    => $failedJobs,
            'recent_failed'  => $recentFailed,
            'healthy'        => $failedJobs === 0 && $pendingJobs < 100,
        ];
    }

    // ── Recent Audit Events ──────────────────────────────────────────────────

    private function recentAuditEvents(): array
    {
        if (! Schema::hasTable('audit_logs')) {
            return [];
        }

        $rows = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->orderByDesc('al.created_at')
            ->limit(15)
            ->select(['u.name as user_name', 'al.action', 'al.model_type', 'al.ip_address', 'al.created_at'])
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'user'       => $row->user_name ?? 'System',
                'action'     => $row->action,
                'entity'     => class_basename($row->model_type),
                'ip'         => $row->ip_address ?? '—',
                'created_at' => $row->created_at,
            ];
        }
        return $out;
    }

    // ── Open System Alerts ───────────────────────────────────────────────────

    private function openSystemAlerts(): array
    {
        if (! Schema::hasTable('alerts')) {
            return ['total' => 0, 'critical' => 0, 'warning' => 0, 'items' => []];
        }

        $total    = DB::table('alerts')->where('is_dismissed', false)->count();
        $critical = DB::table('alerts')->where('is_dismissed', false)->where('severity', 'critical')->count();
        $warning  = DB::table('alerts')->where('is_dismissed', false)->where('severity', 'warning')->count();

        $rows = DB::table('alerts')
            ->where('is_dismissed', false)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->limit(10)
            ->select(['id', 'type', 'severity', 'title', 'message', 'created_at'])
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'         => app('hashids')->encode((int) $row->id),
                'type'       => $row->type,
                'severity'   => $row->severity,
                'title'      => $row->title,
                'message'    => $row->message,
                'created_at' => $row->created_at,
            ];
        }

        return [
            'total'    => (int) $total,
            'critical' => (int) $critical,
            'warning'  => (int) $warning,
            'items'    => $items,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function parseDevice(string $userAgent): string
    {
        if (stripos($userAgent, 'Mobile') !== false)  return 'Mobile';
        if (stripos($userAgent, 'Tablet') !== false)  return 'Tablet';
        if (stripos($userAgent, 'curl') !== false)    return 'CLI';
        if (stripos($userAgent, 'Postman') !== false) return 'API';
        if ($userAgent === '')                         return '—';
        return 'Desktop';
    }
}
