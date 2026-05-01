<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\Admin\Requests\UpdateSettingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SettingsController
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Returns settings grouped by their `group` column.
     */
    public function index(): JsonResponse
    {
        $rows = DB::table('settings')->orderBy('group')->orderBy('key')->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row->group][] = [
                'key'        => $row->key,
                'value'      => json_decode($row->value, true),
                'group'      => $row->group,
                'updated_at' => $row->updated_at,
            ];
        }

        return response()->json(['data' => $grouped]);
    }

    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        $this->settings->set($key, $request->input('value'));

        return response()->json([
            'data' => [
                'key'   => $key,
                'value' => $this->settings->get($key),
            ],
        ]);
    }
}
