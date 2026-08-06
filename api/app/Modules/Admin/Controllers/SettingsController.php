<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Admin\Requests\UpdateSettingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingsController
{
    public function __construct(private readonly SettingsService $settings) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('settings')
            ->leftJoin('users', 'settings.updated_by', '=', 'users.id')
            ->select('settings.*', 'users.name as updated_by_name')
            ->orderBy('settings.group')
            ->orderBy('settings.key')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $value = json_decode($row->value, true);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $companyFallback = match ($row->key) {
                    'company.legal_name' => 'PHILIPPINE OGAMI CORPORATION',
                    'company.address' => 'First Cavite Industrial Estate (FCIE), Dasmariñas, Cavite, Philippines',
                    'company.tin' => '002-841-935-0000',
                    'company.phone' => '+63 (046) 402-1234',
                    'company.email' => 'info@ogami.ph',
                    'company.sales_inbox_email' => 'sales@ogami.ph',
                    'company.public_url' => 'https://ogami.ph',
                    'company.vat_status' => 'VAT Registered',
                    'company.employee_email_domain' => 'ogami.ph',
                    'company.latitude' => 14.2860,
                    'company.longitude' => 120.9345,
                    default => null,
                };
                if ($companyFallback !== null) {
                    $value = $companyFallback;
                }
            }

            $grouped[$row->group][] = [
                'key' => $row->key,
                'value' => $value,
                'group' => $row->group,
                'label' => $row->label,
                'description' => $row->description,
                'updated_by_name' => $row->updated_by_name,
                'updated_at' => $row->updated_at,
            ];
        }

        return response()->json(['data' => $grouped]);
    }

    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        DB::transaction(function () use ($request, $key) {
            $this->settings->set($key, $request->input('value'));

            DB::table('settings')->where('key', $key)->update([
                'updated_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'data' => [
                'key' => $key,
                'value' => $this->settings->get($key),
            ],
        ]);
    }

    public function systemInfo(): JsonResponse
    {
        $dbVersion = 'unknown';
        try {
            $dbVersion = DB::selectOne('SELECT version() as v')->v ?? 'unknown';
        } catch (\Throwable $e) {
            Log::warning('Unable to read the database version for system info.', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['data' => [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'database' => [
                'driver' => config('database.default'),
                'version' => $dbVersion,
            ],
            'cache_driver' => config('cache.default'),
            'queue_driver' => config('queue.default'),
            'session_driver' => config('session.driver'),
            'app_env' => config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'timezone' => config('app.timezone'),
            'server_time' => now()->toIso8601String(),
        ]]);
    }
}
