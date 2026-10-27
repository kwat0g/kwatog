<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\ReturnManagement\Mail\ReturnCaseUpdateMail;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ReturnCaseEmailReliabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_emails_are_valid_deduplicated_and_stable_per_case_event(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        Mail::fake();

        $role = Role::create(['name' => 'Return Manager Test', 'slug' => 'return_manager_test', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('slug', [
            'return_management.manage',
            'return_management.view',
        ])->pluck('id')->all());
        $manager = User::factory()->create(['role_id' => $role->id]);
        $managerWithoutView = User::factory()->create(['role_id' => $role->id]);
        UserPermissionOverride::create([
            'user_id' => $managerWithoutView->id,
            'permission_id' => Permission::query()->where('slug', 'return_management.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $manager->id,
            'reason' => 'Notification route access test.',
        ]);
        $managerWithoutView->flushPermissionsCache();
        $customer = Customer::factory()->create();
        $case = (new ReturnCase)->forceFill([
            'case_number' => 'CASE-RELIABILITY-001',
            'type' => 'customer',
            'status' => 'submitted',
            'customer_id' => $customer->id,
            'created_by' => $manager->id,
            'assigned_to' => $manager->id,
            'preferred_resolution' => 'advice',
            'description' => 'Test case',
        ]);
        $case->save();

        foreach ([
            ['Invalid address', 'not-an-email', true],
            ['First contact', 'Buyer@example.test', true],
            ['Duplicate mailbox', 'buyer@example.test', true],
            ['Inactive contact', 'inactive@example.test', false],
        ] as [$name, $email, $active]) {
            CustomerPortalUser::create([
                'customer_id' => $customer->id,
                'name' => $name,
                'email' => $email,
                'password' => bcrypt('Password1!'),
                'is_active' => $active,
                'password_changed_at' => now(),
            ]);
        }

        $event = $case->events()->create([
            'action' => 'submitted',
            'message' => 'Report submitted',
            'actor_type' => 'internal',
            'actor_name' => 'Test',
            'user_id' => $manager->id,
            'is_public' => true,
        ]);
        $notify = new \ReflectionMethod(ReturnCaseService::class, 'notify');
        $notify->invoke(app(ReturnCaseService::class), $case, 'Report updated', true, $event->id);
        $notify->invoke(app(ReturnCaseService::class), $case, 'Report updated', true, $event->id);

        $queued = Mail::queued(ReturnCaseUpdateMail::class);
        $this->assertCount(2, $queued);
        $this->assertTrue($queued->every(fn (ReturnCaseUpdateMail $mail) => $mail->hasTo('Buyer@example.test')));
        $this->assertCount(1, $queued->pluck('deliveryId')->unique());
        $this->assertSame(
            ReturnCaseUpdateMail::deliveryIdFor($event->id, 'buyer@example.test'),
            $queued->first()->deliveryId,
        );
        $followUps = $manager->notifications()->where('type', 'return.case_updated')->get()
            ->filter(fn ($notification) => str_contains($notification->data['title'] ?? '', 'needs direct follow-up'));
        $this->assertCount(1, $followUps);
        $this->assertSame(0, $managerWithoutView->notifications()->where('type', 'return.case_updated')->count());
        $notice = $manager->notifications()->where('type', 'return.case_updated')->firstOrFail();
        $this->assertSame(ReturnCase::class, $notice->data['subject_type']);
        $this->assertSame((string) $case->id, $notice->data['subject_id']);
        $this->assertSame('/return-management/cases/'.$case->hash_id, $notice->data['link_to']);
        $this->actingAs($manager)->getJson('/api/v1/return-management/cases/'.$case->hash_id)->assertOk();
        $this->actingAs($managerWithoutView)->getJson('/api/v1/return-management/cases/'.$case->hash_id)->assertForbidden();
    }

    public function test_unassigned_case_does_not_fan_out_to_every_manager(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $role = Role::create(['name' => 'Return Manager Test', 'slug' => 'return_manager_test', 'is_system' => false]);
        $role->permissions()->attach(Permission::query()->whereIn('slug', [
            'return_management.manage',
            'return_management.view',
        ])->pluck('id')->all());
        $manager = User::factory()->create(['role_id' => $role->id]);
        $customer = Customer::factory()->create();
        $case = (new ReturnCase)->forceFill([
            'case_number' => 'CASE-UNASSIGNED-001',
            'type' => 'customer',
            'status' => 'submitted',
            'customer_id' => $customer->id,
            'created_by' => $manager->id,
            'assigned_to' => null,
            'preferred_resolution' => 'advice',
            'description' => 'Unassigned test case',
        ]);
        $case->save();

        $notify = new \ReflectionMethod(ReturnCaseService::class, 'notify');
        $notify->invoke(app(ReturnCaseService::class), $case, 'Report updated', false, 1);

        $this->assertSame(0, $manager->notifications()->where('type', 'return.case_updated')->count());
    }

    public function test_customer_returns_only_assignee_does_not_receive_supplier_case_notice(): void
    {
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        $assignee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id'),
            'is_active' => true,
        ]);
        $case = (new ReturnCase)->forceFill([
            'case_number' => 'CASE-SUPPLIER-SCOPE-001',
            'type' => 'supplier',
            'status' => 'submitted',
            'vendor_id' => Vendor::factory()->create()->id,
            'created_by' => $assignee->id,
            'assigned_to' => $assignee->id,
            'preferred_resolution' => 'advice',
            'description' => 'Supplier case access test',
        ]);
        $case->save();

        $notify = new \ReflectionMethod(ReturnCaseService::class, 'notify');
        $notify->invoke(app(ReturnCaseService::class), $case, 'Report updated', false, 1);

        $this->assertSame(0, $assignee->notifications()->where('type', 'return.case_updated')->count());
        $this->actingAs($assignee)->getJson('/api/v1/return-management/cases/'.$case->hash_id)->assertNotFound();
    }
}
