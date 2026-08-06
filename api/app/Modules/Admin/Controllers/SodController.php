<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Models\SodConflictRule;
use App\Modules\Admin\Resources\SodConflictRuleResource;
use App\Modules\Admin\Services\SodService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * REC-01 — read the SoD conflict matrix and the "who violates SoD today" report.
 */
class SodController
{
    public function __construct(private readonly SodService $sod) {}

    /** The matrix — every declared conflict rule. */
    public function index(): AnonymousResourceCollection
    {
        return SodConflictRuleResource::collection(
            SodConflictRule::query()
                ->with(['permissionA:id,slug,name', 'permissionB:id,slug,name'])
                ->orderByDesc('active')
                ->orderBy('code')
                ->get(),
        );
    }

    /** The audit artifact — active non-admin users who hold conflicting pairs. */
    public function violations(): array
    {
        $report = $this->sod->scanAllUsers();

        return [
            'data' => array_map(fn ($row) => [
                'user' => [
                    'id'    => $row['user']->hash_id,
                    'name'  => $row['user']->name,
                    'email' => $row['user']->email,
                    'role'  => $row['user']->role?->name,
                ],
                'violations' => $row['rules']->map(fn (SodConflictRule $r) => [
                    'code'     => $r->code,
                    'name'     => $r->name,
                    'severity' => $r->severity->value,
                    'severity_label' => Str::headline((string) $r->severity->value),
                ])->all(),
            ], $report),
            'meta' => ['total_users_flagged' => count($report)],
        ];
    }
}
