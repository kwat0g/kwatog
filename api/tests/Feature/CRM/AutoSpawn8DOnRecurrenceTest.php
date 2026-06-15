<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Listeners\AutoSpawn8DOnNcrRecurrence;
use App\Modules\CRM\Models\Complaint8DReport;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\NcrSeverity;
use App\Modules\Quality\Enums\NcrSource;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Events\NcrRecurrenceLinked;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3.2.C — AutoSpawn8DOnNcrRecurrence listener.
 *
 * Verifies the bridge between T3.1.D recurrence detection and the
 * customer-complaint 8D follow-through: when a customer-complaint NCR
 * gets linked to a prior recurrence, an 8D shell auto-spawns for the
 * complaint so QC can fill it through the existing editor.
 */
class AutoSpawn8DOnRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actor(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function makeNcr(string $source, ?int $productId = null): NonConformanceReport
    {
        return NonConformanceReport::factory()->create([
            'source'             => $source,
            'severity'           => NcrSeverity::Medium->value,
            'status'             => NcrStatus::Open->value,
            'product_id'         => $productId,
            'defect_description' => 'Recurrence test NCR',
            'affected_quantity'  => 1,
        ]);
    }

    private function dispatchListener(NonConformanceReport $ncr): void
    {
        (new AutoSpawn8DOnNcrRecurrence())->handle(new NcrRecurrenceLinked($ncr));
    }

    public function test_customer_complaint_ncr_recurrence_spawns_8d_shell(): void
    {
        $actor    = $this->actor();
        $customer = Customer::factory()->create();
        $product  = Product::factory()->create();
        $ncr      = $this->makeNcr(NcrSource::CustomerComplaint->value, $product->id);

        $complaint = CustomerComplaint::create([
            'complaint_number' => 'CC-T-'.substr(uniqid(), -6),
            'customer_id'      => $customer->id,
            'product_id'       => $product->id,
            'received_date'    => now()->toDateString(),
            'severity'         => NcrSeverity::Medium->value,
            'description'      => 'recurrence test',
            'affected_quantity' => 1,
            'ncr_id'           => $ncr->id,
            'created_by'       => $actor->id,
        ]);

        $this->assertSame(0, Complaint8DReport::where('complaint_id', $complaint->id)->count());

        $this->dispatchListener($ncr);

        $this->assertSame(1, Complaint8DReport::where('complaint_id', $complaint->id)->count());
    }

    public function test_inspection_fail_ncr_does_not_spawn_8d(): void
    {
        $product = Product::factory()->create();
        $ncr     = $this->makeNcr(NcrSource::InspectionFail->value, $product->id);

        $this->dispatchListener($ncr);

        $this->assertSame(0, Complaint8DReport::query()->count());
    }

    public function test_listener_is_idempotent_when_complaint_already_has_8d(): void
    {
        $actor    = $this->actor();
        $customer = Customer::factory()->create();
        $product  = Product::factory()->create();
        $ncr      = $this->makeNcr(NcrSource::CustomerComplaint->value, $product->id);

        $complaint = CustomerComplaint::create([
            'complaint_number' => 'CC-T-'.substr(uniqid(), -6),
            'customer_id'      => $customer->id,
            'product_id'       => $product->id,
            'received_date'    => now()->toDateString(),
            'severity'         => NcrSeverity::Medium->value,
            'description'      => 'idempotence test',
            'affected_quantity' => 1,
            'ncr_id'           => $ncr->id,
            'created_by'       => $actor->id,
        ]);

        // Pre-existing 8D shell.
        $existing = Complaint8DReport::create(['complaint_id' => $complaint->id]);

        $this->dispatchListener($ncr);
        $this->dispatchListener($ncr); // double-fire — must still be one row

        $this->assertSame(1, Complaint8DReport::where('complaint_id', $complaint->id)->count());
        $this->assertSame((int) $existing->id, (int) Complaint8DReport::where('complaint_id', $complaint->id)->value('id'));
    }
}
