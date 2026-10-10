<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\SettingsService;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\Purchasing\Enums\RfqStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Policies\RequestForQuoteAccessPolicy;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementRoleBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_only_procurement_operators_receive_the_company_pipeline_permission(): void
    {
        foreach (Role::all() as $role) {
            $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
            $operator = in_array($role->slug, ['system_admin', 'purchasing_officer'], true);
            $this->assertSame($operator, $user->hasPermission('purchasing.chain.view'), $role->slug);
            $response = $this->actingAs($user)->getJson('/api/v1/purchasing/chain');
            $operator ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_finance_and_executive_review_does_not_grant_procurement_operations(): void
    {
        foreach (['finance_officer', 'vice_president'] as $slug) {
            $user = $this->user($slug);
            foreach (['purchasing.view', 'purchasing.pr.approve', 'purchasing.po.approve', 'purchasing.rfq.view'] as $permission) {
                $this->assertTrue($user->hasPermission($permission), "$slug: $permission");
            }
            foreach (['purchasing.po.responses.resolve', 'purchasing.chain.view', 'purchasing.pr.create', 'purchasing.po.create', 'purchasing.po.send', 'purchasing.rfq.manage', 'purchasing.supplier_listings.review', 'purchasing.suppliers.manage'] as $permission) {
                $this->assertFalse($user->hasPermission($permission), "$slug: $permission");
            }
            foreach (['purchase-requests', 'purchase-orders', 'rfqs'] as $path) {
                $this->actingAs($user)->getJson("/api/v1/purchasing/$path")->assertOk();
            }
            $this->actingAs($user)->getJson('/api/v1/purchasing/approved-suppliers')->assertForbidden();
            $this->actingAs($user)->getJson('/api/v1/purchasing/supplier-listings')->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/purchasing/purchase-orders', [])->assertForbidden();
            $this->actingAs($user)->postJson('/api/v1/purchasing/purchase-requests', [])->assertForbidden();

            $policy = app(RequestForQuoteAccessPolicy::class);
            $actions = $policy->actionsFor($user, (new RequestForQuote)->forceFill(['status' => RfqStatus::Closed]));
            $this->assertTrue($actions['can_compare']);
            foreach (['can_award', 'can_edit', 'can_publish', 'can_cancel', 'can_capture_quote', 'can_upload_document'] as $action) {
                $this->assertFalse($actions[$action], "$slug: $action");
            }
        }
    }

    public function test_approval_roles_cannot_run_supplier_negotiation_and_buyers_cannot_approve_spending(): void
    {
        $order = PurchaseOrder::factory()->create();
        $response = PurchaseOrderResponse::create([
            'purchase_order_id' => $order->id, 'vendor_id' => $order->vendor_id,
            'response_type' => 'decline', 'status' => 'pending', 'responded_at' => now(),
        ]);
        foreach (['finance_officer', 'vice_president'] as $slug) {
            $user = $this->user($slug);
            $this->actingAs($user)->patchJson("/api/v1/purchasing/purchase-order-responses/{$response->hash_id}/accept", [])->assertForbidden();
            $this->patchJson("/api/v1/purchasing/purchase-order-responses/{$response->hash_id}/reject", ['reason' => 'Decline'])->assertForbidden();
        }
        $buyer = $this->user('purchasing_officer');
        $po = PurchaseOrder::factory()->create();
        $this->actingAs($buyer)->getJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}")->assertOk();
        $this->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/approve", [])->assertForbidden();
        foreach (['purchasing_officer', 'department_head'] as $slug) {
            $user = $this->user($slug);
            $this->assertFalse($user->hasPermission('purchasing.pr.approve'));
        }
    }

    public function test_finance_can_read_only_bank_requests_without_general_hr_access(): void
    {
        $finance = $this->user('finance_officer');
        $employee = Employee::factory()->create();
        $requester = $this->user('employee');
        $requester->forceFill(['employee_id' => $employee->id])->save();
        $changes = ['bank_name' => 'Bank', 'bank_account_no' => '123456789', 'city' => 'Private address'];
        $this->actingAs($requester)->postJson('/api/v1/hr/self-service/profile/request-update', ['changes' => $changes])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/v1/hr/self-service/profile/request-update', ['changes' => ['city' => 'Private address']])
            ->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->postJson('/api/v1/hr/self-service/profile/request-update', ['changes' => ['bank_account_no' => '987654321']])
            ->assertCreated()->assertJsonPath('data.status', 'pending');

        $bank = ProfileUpdateRequest::query()
            ->where('requested_by', $requester->id)
            ->where('requires_finance', true)
            ->oldest('id')
            ->firstOrFail();
        $hrReviewer = $this->user('hr_officer');
        $this->actingAs($hrReviewer)->getJson('/api/v1/hr/profile-update-requests')
            ->assertOk()->assertJsonCount(3, 'data');
        $this->patchJson("/api/v1/hr/profile-update-requests/{$bank->hash_id}/review", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'pending_finance');

        $bank->refresh();
        $this->assertSame('pending_finance', $bank->status);
        $this->assertSame((int) $hrReviewer->id, (int) $bank->reviewed_by);
        $this->assertNotNull($bank->reviewed_at);
        $this->assertNull($bank->finance_reviewed_by);
        $this->assertNull($employee->refresh()->bank_name);
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => $bank->getMorphClass(),
            'model_id' => $bank->id,
            'user_id' => $hrReviewer->id,
            'action' => 'updated',
        ]);

        $this->actingAs($finance)->getJson('/api/v1/hr/profile-update-requests')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bank->hash_id)
            ->assertJsonMissingPath('data.0.changes.city');
        $this->actingAs($finance)->getJson('/api/v1/hr/profile-update-requests?status=pending')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($finance)->getJson('/api/v1/hr/profile-update-requests/options')->assertOk();
        $this->actingAs($finance)->getJson('/api/v1/hr/employees')->assertForbidden();
        $this->actingAs($finance)->patchJson("/api/v1/hr/profile-update-requests/{$bank->hash_id}/review", ['action' => 'approve'])->assertForbidden();
        $auditCount = DB::table('audit_logs')->where('model_type', $bank->getMorphClass())->where('model_id', $bank->id)->count();
        $this->actingAs($finance)->patchJson("/api/v1/hr/profile-update-requests/{$bank->hash_id}/finance-review", ['action' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $bank->refresh();
        $this->assertSame('approved', $bank->status);
        $this->assertSame((int) $finance->id, (int) $bank->finance_reviewed_by);
        $this->assertSame('Bank', $employee->fresh()->bank_name);
        $this->assertSame('123456789', $employee->fresh()->bank_account_no);
        $this->assertSame('Private address', $employee->fresh()->city);
        $this->assertSame($auditCount + 1, DB::table('audit_logs')->where('model_type', $bank->getMorphClass())->where('model_id', $bank->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'model_type' => $bank->getMorphClass(),
            'model_id' => $bank->id,
            'user_id' => $finance->id,
            'action' => 'updated',
        ]);
    }

    public function test_chain_migration_is_repeatable_reversible_and_preserves_other_grants(): void
    {
        $migration = require database_path('migrations/2026_10_02_000002_scope_procurement_chain_access.php');
        $migration->down();
        $finance = $this->user('finance_officer');
        $legacy = Permission::where('slug', 'purchasing.view')->firstOrFail();
        UserPermissionOverride::create([
            'user_id' => $finance->id, 'permission_id' => $legacy->id, 'type' => 'revoke',
            'reason' => 'Existing restriction', 'granted_by' => $finance->id,
        ]);
        $buyer = $this->user('purchasing_officer');
        UserPermissionOverride::create([
            'user_id' => $buyer->id, 'permission_id' => $legacy->id, 'type' => 'revoke',
            'reason' => 'Existing restriction', 'granted_by' => $finance->id,
        ]);
        $pivotsBefore = DB::table('role_permissions')->count();
        $migration->up();
        $migration->up();
        $this->assertSame($pivotsBefore + 2, DB::table('role_permissions')->count());
        $this->assertFalse($finance->fresh()->hasPermission('purchasing.view'));
        $this->assertFalse($finance->fresh()->hasPermission('purchasing.chain.view'));
        $this->assertTrue($this->user('purchasing_officer')->hasPermission('purchasing.chain.view'));
        $this->assertFalse($buyer->fresh()->hasPermission('purchasing.chain.view'));
        $migration->down();
        $this->assertSame($pivotsBefore, DB::table('role_permissions')->count());
        $this->assertDatabaseHas('user_permission_overrides', ['user_id' => $finance->id, 'permission_id' => $legacy->id, 'type' => 'revoke']);
    }

    public function test_procurement_widgets_do_not_expand_read_access(): void
    {
        $this->seed(DashboardWidgetSeeder::class);
        $this->assertDatabaseHas('dashboard_widgets', [
            'key' => 'purchasing.supplier_perf', 'permission' => 'purchasing.suppliers.performance.view',
            'link_path' => '/purchasing/suppliers/performance',
        ]);
        $reader = $this->user('production_manager');
        $buyer = $this->user('purchasing_officer');
        PurchaseOrder::factory()->create(['created_by' => $reader->id])->forceFill(['status' => 'approved'])->save();
        PurchaseOrder::factory()->create(['created_by' => $buyer->id])->forceFill(['status' => 'approved'])->save();
        $analytics = app(CoreWidgetAnalytics::class);
        $this->assertSame(1, $analytics->payload('purchasing.open_pos', $reader)['total']);
        $this->assertSame(2, $analytics->payload('purchasing.open_pos', $buyer)['total']);
        PurchaseRequest::factory()->create(['requested_by' => $reader->id]);
        PurchaseRequest::factory()->create(['requested_by' => $buyer->id]);
        $settings = \Mockery::mock(SettingsService::class);
        $settings->shouldReceive('requiredInt')->andReturn(7);
        $this->app->instance(SettingsService::class, $settings);
        $summary = app(DashboardWidgetDataService::class)->summaries(['purchasing.open_prs', 'purchasing.open_pos'], $reader);
        $this->assertTrue($summary['purchasing.open_prs']['available']);
        $this->assertSame('1', $summary['purchasing.open_prs']['value']);
        $this->assertTrue($summary['purchasing.open_pos']['available']);
        $this->assertSame('1', $summary['purchasing.open_pos']['value']);
    }

    private function user(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id'), 'is_active' => true]);
    }
}
