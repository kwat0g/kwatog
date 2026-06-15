<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\ComplaintService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class Complaint8dFinalizeGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function complaint(): CustomerComplaint
    {
        $by = $this->user();
        return app(ComplaintService::class)->create([
            'customer_id'       => Customer::factory()->create()->id,
            'received_date'     => now()->toDateString(),
            'severity'          => 'medium',
            'description'       => 'Customer reports surface scratch on pivot cap',
            'affected_quantity' => 10,
        ], $by);
    }

    private function fillAllEight(Complaint8DReport $r, array $skip = []): void
    {
        $fields = [
            'd1_team' => 'QC, Production, Engineering',
            'd2_problem' => 'Surface scratch on outer face',
            'd3_containment' => 'Quarantine batch B-204; 100% inspect WIP',
            'd4_root_cause' => 'Worn ejector pin causing scuff during release',
            'd5_corrective_action' => 'Replace ejector pin; revise PM schedule',
            'd6_verification' => '500-piece run with zero scratch defects',
            'd7_prevention' => 'Add ejector-pin wear check to weekly PM',
            'd8_recognition' => 'Recognize Maintenance team in monthly QMS review',
        ];
        foreach ($fields as $k => $v) {
            if (in_array($k, $skip, true)) continue;
            $r->{$k} = $v;
        }
        $r->save();
    }

    public function test_finalize_rejects_when_d1_team_empty(): void
    {
        $by = $this->user();
        $c = $this->complaint();
        $this->fillAllEight($c->eightDReport, skip: ['d1_team']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('d1_team');

        app(ComplaintService::class)->finalize8D($c->fresh(), $by);
    }

    public function test_finalize_rejects_when_d8_empty_string_after_trim(): void
    {
        $by = $this->user();
        $c = $this->complaint();
        $this->fillAllEight($c->eightDReport);
        // Whitespace-only d8 must still be rejected.
        $c->eightDReport->update(['d8_recognition' => "   \n\t  "]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('d8_recognition');

        app(ComplaintService::class)->finalize8D($c->fresh(), $by);
    }

    public function test_finalize_succeeds_when_all_eight_populated(): void
    {
        $by = $this->user();
        $c = $this->complaint();
        $this->fillAllEight($c->eightDReport);

        $report = app(ComplaintService::class)->finalize8D($c->fresh(), $by);

        $this->assertNotNull($report->finalized_at);
        $this->assertSame((int) $by->id, (int) $report->finalized_by);
    }

    public function test_finalize_already_finalized_is_idempotent_noop(): void
    {
        $by = $this->user();
        $c = $this->complaint();
        $this->fillAllEight($c->eightDReport);

        $first  = app(ComplaintService::class)->finalize8D($c->fresh(), $by);
        $stamp  = $first->finalized_at;

        // Wipe d1_team after finalize — should NOT re-trigger the guard
        // because the report is already locked.
        $c->eightDReport->forceFill(['d1_team' => ''])->save();

        $second = app(ComplaintService::class)->finalize8D($c->fresh(), $by);

        $this->assertEquals($stamp->timestamp, $second->finalized_at->timestamp);
    }
}
