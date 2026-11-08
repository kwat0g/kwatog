<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Events\ReturnRequestChanged;
use App\Modules\ReturnManagement\Events\ReturnCaseChanged;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReturnRequestRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_request_writes_coalesce_to_one_latest_refresh(): void
    {
        $user = User::factory()->create();
        $rma = null;

        DB::transaction(function () use ($user, &$rma): void {
            $rma = ReturnRequest::create([
                'rma_number' => 'RMA-RT-'.uniqid(),
                'type' => 'customer_return',
                'status' => ReturnRequestStatus::Draft,
                'created_by' => $user->id,
            ]);
            $rma->update(['internal_notes' => 'Edited inside transaction']);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', ReturnRequestChanged::class)
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($rma->hash_id, $payload['hashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertSame($rma->fresh()->updated_at->toISOString(), $payload['version']);
    }

    public function test_manual_inspection_handoff_update_stages_a_return_refresh(): void
    {
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-HANDOFF-'.uniqid(),
            'type' => 'customer_return',
            'status' => ReturnRequestStatus::Inspected,
        ]);

        app(ReturnRequestService::class)->markInspectionHandoffManual($rma->id, 'Please retry inspection staging.');

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => ReturnRequestChanged::class,
        ]);
    }

    public function test_customer_return_update_refreshes_its_customer_portal_and_linked_case(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $case = new ReturnCase;
        $case->forceFill([
            'case_number' => 'CASE-RMA-'.uniqid(),
            'type' => 'customer',
            'status' => 'submitted',
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'preferred_resolution' => 'advice',
            'description' => 'Linked RMA refresh regression.',
        ])->save();
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-CASE-'.uniqid(),
            'type' => 'customer_return',
            'status' => ReturnRequestStatus::Draft,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
        ]);
        $case->forceFill(['return_request_id' => $rma->id])->save();
        DB::table('event_outbox')->delete();

        DB::transaction(function () use ($rma): void {
            $rma->fresh()->update(['internal_notes' => 'A linked status/detail field changed.']);
        });

        $rmaPayload = json_decode((string) DB::table('event_outbox')
            ->where('event_type', ReturnRequestChanged::class)
            ->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($customer->hash_id, $rmaPayload['customerHashId']);

        $casePayload = json_decode((string) DB::table('event_outbox')
            ->where('event_type', ReturnCaseChanged::class)
            ->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($case->hash_id, $casePayload['hashId']);
        $this->assertSame('related_updated', $casePayload['action']);
    }
}
