<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Services\ComplaintService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Complaint8dDueAtStampingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_create_stamps_all_three_due_ats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $by = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $c = app(ComplaintService::class)->create([
            'customer_id'       => Customer::factory()->create()->id,
            'received_date'     => now()->toDateString(),
            'severity'          => 'medium',
            'description'       => 'Defect on flange',
            'affected_quantity' => 5,
        ], $by);

        $this->assertNotNull($c->d3_due_at);
        $this->assertNotNull($c->d4_due_at);
        $this->assertNotNull($c->finalize_due_at);

        $this->assertEquals(
            now()->addHours(48)->timestamp,
            $c->d3_due_at->timestamp,
            'd3 must be created_at + 48h',
        );
        $this->assertEquals(
            now()->addDays(7)->timestamp,
            $c->d4_due_at->timestamp,
            'd4 must be created_at + 7d',
        );
        $this->assertEquals(
            now()->addDays(30)->timestamp,
            $c->finalize_due_at->timestamp,
            'finalize must be created_at + 30d',
        );
        $this->assertSame([], $c->sla_alert_levels ?? []);

        Carbon::setTestNow();
    }
}
