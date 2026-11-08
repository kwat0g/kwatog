<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Events\PayrollPeriodChanged;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollPeriodRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_period_changes_stage_one_coalesced_durable_refresh_in_the_business_transaction(): void
    {
        $actor = User::factory()->create();
        $period = null;

        DB::transaction(function () use ($actor, &$period): void {
            $period = PayrollPeriod::factory()->create([
                'created_by' => $actor->id,
                'status' => 'draft',
            ]);

            $period->forceFill(['status' => 'computed'])->save();
            $period->forceFill(['status' => 'approved'])->save();
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\Payroll\\Events\\PayrollPeriodChanged')
            ->get();

        $this->assertCount(1, $messages, 'All period writes in a transaction must coalesce to one refresh ping.');

        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($period->hash_id, $payload['hashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertNotEmpty($payload['version']);
        $this->assertSame('pending', $messages->first()->status);
    }

    public function test_disbursement_proof_changes_refresh_the_parent_period_when_status_is_unchanged(): void
    {
        $period = PayrollPeriod::factory()->create([
            'status' => 'finalized',
            'disbursement_status' => 'partially_disbursed',
        ]);
        $uploader = User::factory()->create();
        DB::table('event_outbox')->where('event_type', PayrollPeriodChanged::class)->delete();

        DB::transaction(function () use ($period, $uploader): void {
            DisbursementProof::create([
                'payroll_period_id' => $period->id,
                'proof_type' => 'bank_confirmation',
                'file_name' => 'confirmation.pdf',
                'file_path' => 'proofs/confirmation.pdf',
                'disbursed_amount' => '100.00',
                'disbursement_date' => '2026-11-01',
                'uploaded_by' => $uploader->id,
            ]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', PayrollPeriodChanged::class)
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($period->hash_id, $payload['hashId']);
        $this->assertSame('related_updated', $payload['action']);
        $this->assertNotEmpty($payload['version']);
    }
}
