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
            $grouped[$row->group][] = [
                'key'             => $row->key,
                'value'           => json_decode($row->value, true),
                'group'           => $row->group,
                'label'           => $row->label,
                'description'     => $row->description,
                'updated_by_name' => $row->updated_by_name,
                'updated_at'      => $row->updated_at,
            ];
        }

        return response()->json(['data' => $grouped]);
    }

    public function update(UpdateSettingRequest $request, string $key): JsonResponse
    {
        $this->settings->set($key, $request->input('value'));

        DB::table('settings')->where('key', $key)->update([
            'updated_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => [
                'key'   => $key,
                'value' => $this->settings->get($key),
            ],
        ]);
    }
}
