<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\ReturnManagement\Events\ReturnRequestUpdated;
use App\Modules\ReturnManagement\Listeners\EmailPartyOnReturnRequestUpdated;
use App\Modules\ReturnManagement\Mail\ReturnRequestUpdateMail;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ReturnRequestUpdateEmailSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_notice_uses_event_time_party_and_rma_values_and_stable_delivery_key(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Mail::fake();

        $creator = User::factory()->create();
        $customer = Customer::factory()->create([
            'name' => 'Original Customer',
            'contact_person' => 'Aiko Tanaka',
            'email' => 'original@example.test',
        ]);
        $rma = (new ReturnRequest)->forceFill([
            'rma_number' => 'RMA-2026-0042',
            'type' => 'customer_return',
            'status' => 'approved',
            'customer_id' => $customer->id,
            'created_by' => $creator->id,
            'reason_description' => 'Damaged surface',
            'resolution' => 'credit_note',
            'refund_amount' => '1234.50',
            'return_date' => '2026-11-18',
        ]);
        $rma->save();

        $event = new ReturnRequestUpdated($rma, 'approved');
        $this->assertSame('Approved', $event->emailSnapshot['status_label']);
        $this->assertSame('original@example.test', $event->emailSnapshot['party_email']);
        $this->assertArrayNotHasKey('reason_description', $event->emailSnapshot);
        $this->assertArrayNotHasKey('refund_amount', $event->emailSnapshot);
        $rma->update([
            'status' => 'completed',
            'reason_description' => 'Changed after notification',
            'refund_amount' => '9999.99',
        ]);
        $customer->update([
            'name' => 'Changed Customer',
            'contact_person' => 'Changed Contact',
            'email' => 'changed@example.test',
        ]);

        $listener = app(EmailPartyOnReturnRequestUpdated::class);
        $listener->handle($event);
        $listener->handle($event);

        $queued = Mail::queued(ReturnRequestUpdateMail::class);
        $this->assertCount(2, $queued);
        $this->assertTrue($queued->every(fn (ReturnRequestUpdateMail $mail) => $mail->hasTo('original@example.test')));
        $this->assertCount(1, $queued->pluck('deliveryId')->unique());

        $html = $queued->first()->render();
        $this->assertStringContainsString('Approved', $html);
        $this->assertStringContainsString('Aiko Tanaka', $html);
        $this->assertStringNotContainsString('Damaged surface', $html);
        $this->assertStringNotContainsString('1,234.50', $html);
        $this->assertStringNotContainsString('Changed after notification', $html);
        $this->assertStringNotContainsString('9,999.99', $html);
        $this->assertStringNotContainsString('Changed Contact', $html);
    }
}
