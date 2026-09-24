<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Services\ComplaintService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintViewPermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $qcInspector;
    private User $employee;
    private User $csr;
    private CustomerComplaint $complaint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->withRole('system_admin')->create(['is_active' => true]);
        $this->qcInspector = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->employee = User::factory()->withRole('employee')->create(['is_active' => true]);
        $this->csr = User::factory()->withRole('customer_service_officer')->create(['is_active' => true]);

        $this->complaint = app(ComplaintService::class)->create([
            'customer_id'       => Customer::factory()->create()->id,
            'received_date'     => now()->toDateString(),
            'severity'          => 'medium',
            'description'       => 'Permission boundary test complaint',
            'affected_quantity' => 1,
        ], $this->admin);
    }

    public function test_qc_inspector_can_view_complaints_and_options_but_cannot_mutate(): void
    {
        $this->actingAs($this->qcInspector, 'sanctum')
            ->getJson('/api/v1/crm/complaints')
            ->assertOk();

        $this->actingAs($this->qcInspector, 'sanctum')
            ->getJson('/api/v1/crm/complaints/options')
            ->assertOk();

        $this->actingAs($this->qcInspector, 'sanctum')
            ->getJson("/api/v1/crm/complaints/{$this->complaint->hash_id}")
            ->assertOk();

        $this->actingAs($this->qcInspector, 'sanctum')
            ->postJson('/api/v1/crm/complaints', [
                'customer_id'       => Customer::factory()->create()->hash_id,
                'received_date'     => now()->toDateString(),
                'severity'          => 'medium',
                'description'       => 'Unauthorized create attempt',
                'affected_quantity' => 1,
            ])
            ->assertForbidden();
    }

    public function test_unprivileged_role_cannot_view_complaints(): void
    {
        $this->actingAs($this->employee, 'sanctum')
            ->getJson('/api/v1/crm/complaints')
            ->assertForbidden();

        $this->actingAs($this->employee, 'sanctum')
            ->getJson("/api/v1/crm/complaints/{$this->complaint->hash_id}")
            ->assertForbidden();
    }

    public function test_customer_service_officer_can_both_view_and_manage_complaints(): void
    {
        $this->actingAs($this->csr, 'sanctum')
            ->getJson('/api/v1/crm/complaints')
            ->assertOk();

        $this->actingAs($this->csr, 'sanctum')
            ->getJson("/api/v1/crm/complaints/{$this->complaint->hash_id}")
            ->assertOk();
    }
}
