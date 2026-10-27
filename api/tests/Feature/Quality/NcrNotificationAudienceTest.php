<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrRecurrenceDetector;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NcrNotificationAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.quality', true, 'modules');
    }

    public function test_recurrence_notice_excludes_users_who_lost_ncr_view_permission(): void
    {
        $qcInspector = $this->userWithRole('qc_inspector');
        $productionManager = $this->userWithRole('production_manager');
        $this->revoke($qcInspector, 'quality.ncr.view');

        $product = Product::factory()->create();
        $prior = NonConformanceReport::factory()->create(['product_id' => $product->id]);
        $recurrence = NonConformanceReport::factory()->create([
            'product_id' => $product->id,
            'recurrence_of_ncr_id' => $prior->id,
        ]);

        $this->assertSame(1, app(NcrRecurrenceDetector::class)->notify($recurrence));
        $this->assertSame(0, $qcInspector->notifications()->where('type', 'ncr.recurrence')->count());
        $notice = $productionManager->notifications()->where('type', 'ncr.recurrence')->firstOrFail();
        $this->assertSame("/quality/ncrs/{$recurrence->hash_id}", $notice->data['link_to']);
        $this->assertSame(NonConformanceReport::class, $notice->data['subject_type']);
        $this->assertSame((string) $recurrence->id, $notice->data['subject_id']);

        $this->actingAs($productionManager)->getJson('/api/v1/quality/ncrs/'.$recurrence->hash_id)->assertOk();
        $this->actingAs($qcInspector)->getJson('/api/v1/quality/ncrs/'.$recurrence->hash_id)->assertForbidden();
    }

    public function test_ncr_action_owner_choices_and_validation_require_view_permission(): void
    {
        $admin = $this->userWithRole('system_admin');
        $ncr = NonConformanceReport::factory()->create(['created_by' => $admin->id]);
        $ineligible = $this->userWithRole('warehouse_staff');
        $eligible = $this->userWithRole('qc_inspector');

        $this->actingAs($admin)->getJson('/api/v1/quality/ncrs/assignees')
            ->assertOk()
            ->assertJsonMissing(['id' => $ineligible->hash_id])
            ->assertJsonFragment(['id' => $eligible->hash_id]);

        $payload = ['action_type' => 'corrective', 'description' => 'Update inspection criteria.'];
        $this->actingAs($admin)->postJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/actions', $payload + [
            'owner_id' => $ineligible->hash_id,
        ])->assertUnprocessable()->assertJsonValidationErrors('owner_id');

        $this->actingAs($admin)->postJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/actions', $payload + [
            'owner_id' => $eligible->hash_id,
        ])->assertCreated();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);
    }

    private function revoke(User $user, string $permission): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id' => $user->id,
            'permission_id' => Permission::query()->where('slug', $permission)->value('id'),
            'type' => 'revoke',
            'granted_by' => $this->userWithRole('system_admin')->id,
            'reason' => 'Permission removed for notification audience coverage.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
