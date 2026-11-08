<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollAdjustmentRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_create_status_initialization_stages_one_coalesced_refresh(): void
    {
        $maker = User::factory()->create();
        $period = PayrollPeriod::factory()->create();
        $period->forceFill(['status' => 'finalized'])->save();
        $employee = Employee::factory()->create();
        $payroll = Payroll::factory()->create([
            'payroll_period_id' => $period->id,
            'employee_id' => $employee->id,
        ]);

        $adjustment = DB::transaction(function () use ($maker, $period, $employee, $payroll): PayrollAdjustment {
            $adjustment = PayrollAdjustment::query()->create([
                'payroll_period_id' => $period->id,
                'employee_id' => $employee->id,
                'original_payroll_id' => $payroll->id,
                'type' => 'underpayment',
                'amount' => '500.00',
                'reason' => 'Realtime regression',
                'created_by' => $maker->id,
            ]);
            $adjustment->forceFill(['status' => 'pending'])->save();

            return $adjustment;
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Payroll\\Events\\PayrollAdjustmentChanged')
            ->get();

        $this->assertCount(1, $messages, 'Create and pending status writes must coalesce to one after-commit refresh.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['hashId']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);

        DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Payroll\\Events\\PayrollAdjustmentChanged')
            ->delete();
        $adjustment->forceFill(['status' => 'approved'])->save();

        $decision = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Payroll\\Events\\PayrollAdjustmentChanged')
            ->first();
        $this->assertNotNull($decision, 'A later approval decision must stage a refresh.');
        $decisionPayload = json_decode((string) $decision->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('updated', $decisionPayload['action']);
    }
}
