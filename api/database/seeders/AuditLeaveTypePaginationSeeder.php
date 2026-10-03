<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Seeder;
use RuntimeException;

class AuditLeaveTypePaginationSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') || ! filter_var(env('AUDIT_E2E_TESTING', false), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Audit leave-type fixtures require AUDIT_E2E_TESTING=true outside production.');
        }

        foreach (range(0, 100) as $index) {
            $type = LeaveType::withTrashed()->firstOrNew([
                'code' => 'AUDPG'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
            ]);
            $type->fill([
                'name' => 'Audit pagination '.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'default_balance' => 0,
                'is_paid' => true,
                'is_active' => true,
            ]);

            if ($type->isDirty()) {
                $type->save();
            }

            if ($type->trashed()) {
                $type->restore();
            }
        }
    }
}
