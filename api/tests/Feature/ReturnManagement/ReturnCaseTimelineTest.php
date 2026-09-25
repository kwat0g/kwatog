<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\SupplyChain\Models\Delivery;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnCaseTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_and_timeline_use_the_same_clock_when_database_timezone_differs(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Storage::fake('local');
        DB::statement("SET TIME ZONE 'UTC'");
        $this->travelTo(Carbon::parse('2026-10-08 08:25:00', 'Asia/Manila'));
        $user = User::factory()->create(['role_id' => Role::where('slug', 'customer_service_officer')->value('id')]);
        $order = SalesOrder::factory()->create(['customer_id' => Customer::factory()->create()->id, 'created_by' => $user->id]);
        $line = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'quantity' => 10, 'quantity_delivered' => 10]);
        $delivery = Delivery::create(['delivery_number' => 'DEL-TIMELINE', 'sales_order_id' => $order->id,
            'status' => 'delivered', 'scheduled_date' => now()->toDateString(), 'delivered_at' => now(), 'created_by' => $user->id]);
        $deliveryLine = $delivery->items()->create(['sales_order_item_id' => $line->id, 'quantity' => 10, 'unit_price' => '25']);
        $case = $this->actingAs($user)->postJson('/api/v1/return-management/cases', [
            'source_kind' => 'delivery', 'source_id' => $delivery->hash_id, 'request_key' => (string) Str::uuid(),
            'description' => 'Missing one piece.', 'preferred_resolution' => 'credit',
            'lines' => [['source_line_id' => $deliveryLine->hash_id, 'received_quantity' => '9', 'defective_quantity' => '0']],
        ])->assertCreated()->json('data');
        $this->assertSame($case['created_at'], $case['events'][0]['created_at']);

        $this->travel(2)->minutes();
        $reply = $this->postJson('/api/v1/return-management/cases/'.$case['id'].'/actions', [
            'action' => 'reply', 'message' => 'Shipment checked.',
        ])->assertOk()->json('data.events.1');
        $this->assertSame(now()->toIso8601String(), $reply['created_at']);

        $this->travel(1)->minute();
        $this->postJson('/api/v1/return-management/cases/'.$case['id'].'/attachments', [
            'file' => UploadedFile::fake()->create('proof.pdf', 5, 'application/pdf'),
        ])->assertCreated();
        $record = $this->getJson('/api/v1/return-management/cases/'.$case['id'])->assertOk()->json('data');
        $this->assertSame(now()->toIso8601String(), $record['events'][2]['created_at']);
        $this->assertSame($record['attachments'][0]['created_at'], $record['events'][2]['created_at']);
        $this->assertSame(3, ReturnCase::findOrFail(ReturnCase::tryDecodeHash($case['id']))->events()->count());
    }
    public function test_history_repair_is_auditable_idempotent_and_preserves_explicit_timestamps(): void
    {
        DB::statement("SET TIME ZONE 'UTC'");
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $makeCase = fn (string $number, ?int $legacy = null) => DB::table('return_cases')->insertGetId([
            'case_number' => $number, 'type' => 'customer', 'customer_id' => $customer->id,
            'created_by' => $user->id, 'preferred_resolution' => 'advice', 'description' => 'Clock repair',
            'created_at' => '2026-09-25 06:11:48', 'updated_at' => '2026-09-25 06:15:48',
            'legacy_discrepancy_id' => $legacy,
        ]);
        $caseId = $makeCase('CASE-CLOCK');
        $legacyId = $makeCase('CASE-LEGACY-CLOCK', 1234);
        $event = fn (int $case, string $action, string $time, array $metadata = []) => DB::table('return_case_events')->insertGetId([
            'return_case_id' => $case, 'action' => $action, 'message' => 'Event', 'actor_type' => 'internal',
            'actor_name' => 'Test', 'user_id' => $user->id, 'created_at' => $time, 'metadata' => json_encode($metadata),
        ]);
        $submitted = $event($caseId, 'submitted', '2026-09-24 22:11:48');
        $reply = $event($caseId, 'reply', '2026-09-24 22:12:48', ['preserved' => true]);
        $newEvent = $event($caseId, 'reply', '2026-09-25 06:15:48', ['timestamp_timezone' => 'Asia/Manila']);
        $legacyEvent = $event($legacyId, 'submitted', '2026-09-24 22:11:48');
        $migration = require database_path('migrations/2026_09_25_210000_align_return_case_event_timestamps.php');
        $migration->up();
        $migration->up();
        $this->assertSame('2026-09-25 06:11:48', DB::table('return_case_events')->find($submitted)->created_at);
        $repaired = DB::table('return_case_events')->find($reply);
        $this->assertSame('2026-09-25 06:12:48', $repaired->created_at);
        $this->assertTrue(json_decode($repaired->metadata, true)['preserved']);
        $this->assertSame('2026-09-25 06:15:48', DB::table('return_case_events')->find($newEvent)->created_at);
        $this->assertSame('2026-09-24 22:11:48', DB::table('return_case_events')->find($legacyEvent)->created_at);
        $migration->down();
        $this->assertSame('2026-09-24 22:12:48', DB::table('return_case_events')->find($reply)->created_at);
    }

}
