<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnCaseEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReturnCaseRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_and_public_timeline_mutations_coalesce_to_one_latest_refresh_after_commit(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $case = null;

        DB::transaction(function () use ($user, $customer, &$case): void {
            $case = new ReturnCase;
            $case->forceFill([
                'case_number' => 'CASE-RT-'.uniqid(),
                'type' => 'customer',
                'status' => 'submitted',
                'customer_id' => $customer->id,
                'created_by' => $user->id,
                'preferred_resolution' => 'advice',
                'description' => 'Realtime case regression.',
            ])->save();
            $case = $case->fresh();
            $case->forceFill(['status' => 'under_review'])->save();
            $event = new ReturnCaseEvent;
            $event->forceFill([
                'return_case_id' => $case->id,
                'action' => 'reply',
                'message' => 'We are reviewing this report.',
                'actor_type' => 'internal',
                'actor_name' => 'Case Reviewer',
                'user_id' => $user->id,
                'is_public' => true,
            ])->save();
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', 'App\\Modules\\ReturnManagement\\Events\\ReturnCaseChanged')
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($case->hash_id, $payload['hashId']);
        $this->assertSame('reply', $payload['action']);
        $this->assertTrue($payload['publicChange']);
    }
}
