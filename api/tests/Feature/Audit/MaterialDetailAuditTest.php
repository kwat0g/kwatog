<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Common\Models\AuditLog;
use App\Common\Traits\HasAuditLog;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSalaryHistory;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialDetailAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_models_opt_into_immutable_detail_audit(): void
    {
        foreach ([
            \App\Modules\Inventory\Models\StockAdjustment::class,
            ProfileUpdateRequest::class,
            EmployeeSalaryHistory::class,
            \App\Modules\Production\Models\WorkOrderOutput::class,
            \App\Modules\Quality\Models\InspectionMeasurement::class,
        ] as $model) {
            $this->assertContains(HasAuditLog::class, class_uses_recursive($model));
        }
    }

    public function test_profile_detail_audit_has_actor_before_after_and_redacts_bank_values(): void
    {
        $actor = User::factory()->create();
        $employee = Employee::factory()->create();
        $this->actingAs($actor)->withHeaders(['X-Request-ID' => 'material-audit-test-1']);

        $request = ProfileUpdateRequest::create([
            'employee_id' => $employee->id,
            'requested_by' => $actor->id,
            'status' => 'pending',
            'changes' => ['bank_account' => '4111111111111111', 'display_name' => 'Updated'],
            'note' => 'Employee requested profile correction',
        ]);
        $request->update(['status' => 'approved']);

        $rows = AuditLog::query()->where('model_type', $request->getMorphClass())
            ->where('model_id', $request->getKey())->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($actor->id, $rows[0]->user_id);
        $this->assertSame('user', $rows[0]->actor_type);
        $this->assertSame('***', $rows[0]->new_values['changes']['bank_account']);
        $this->assertSame('approved', $rows[1]->new_values['status']);
        $this->assertNotNull($rows[1]->old_values);
        $this->assertNotNull($rows[1]->source_command);
    }
}
