<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Models\WorkflowDefinition;
use App\Common\Services\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_creates_pending_records(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'leave_request',
            'name'          => 'Leave',
            'steps'         => [
                ['order' => 1, 'role' => 'department_head'],
                ['order' => 2, 'role' => 'hr_officer'],
            ],
        ]);

        $approvable = $this->fakeApprovable();
        app(ApprovalService::class)->submit($approvable, 'leave_request');

        $records = app(ApprovalService::class)->chain($approvable);
        $this->assertCount(2, $records);
        $this->assertSame('pending', $records[0]->action);
        $this->assertSame('pending', $records[1]->action);
    }

    public function test_threshold_skips_step_below_amount(): void
    {
        WorkflowDefinition::create([
            'workflow_type' => 'purchase_order',
            'name'          => 'PO',
            'steps'         => [
                ['order' => 1, 'role' => 'purchasing_officer'],
                ['order' => 2, 'role' => 'finance_officer'],
                ['order' => 3, 'role' => 'system_admin', 'threshold' => 50000.00],
            ],
        ]);

        $approvable = $this->fakeApprovable();
        app(ApprovalService::class)->submit($approvable, 'purchase_order', amount: 10000.00);

        $records = app(ApprovalService::class)->chain($approvable);
        $this->assertSame('pending', $records[0]->action);
        $this->assertSame('pending', $records[1]->action);
        $this->assertSame('skipped', $records[2]->action);
    }

    private function fakeApprovable(): Model
    {
        return new class extends Model {
            protected $table = 'fakes';
            public $exists = true;
            public function getKey() { return 1; }
            public function getMorphClass(): string { return 'fake_approvable'; }
        };
    }
}
