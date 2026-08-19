<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Models\DashboardLayout;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `approvals.pending` as a worklist rather than a count.
 *
 * This is the one widget every approval-carrying role holds, and
 * `department_head` — six approve-type grants, no bespoke dashboard page — reads
 * its queue here or nowhere. A bare "3" did not say which of the three had been
 * waiting three days.
 *
 * The provider delegates to ApprovalBoardService so the tile and /approvals
 * resolve the same rows through the same delegation-aware role match; the tests
 * below pin that the tile stays SELF-scoped while doing it.
 */
class ApprovalsWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
    }

    private function actingAsRole(string $slug): User
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'appr+'.substr(uniqid(), -8).'@t.test',
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function pendingApproval(string $roleSlug, int $hoursAgo): PurchaseRequest
    {
        $pr = PurchaseRequest::factory()->create();

        DB::table('approval_records')->insert([
            'approvable_type' => PurchaseRequest::class,
            'approvable_id' => $pr->id,
            'step_order' => 1,
            'role_slug' => $roleSlug,
            'action' => 'pending',
            'created_at' => now()->subHours($hoursAgo),
        ]);

        return $pr;
    }

    private function putOnLayout(User $user): void
    {
        DashboardLayout::create([
            'owner_type' => DashboardLayout::OWNER_USER,
            'owner_id' => $user->id,
            'widget_key' => 'approvals.pending',
            'position_x' => 0,
            'position_y' => 0,
            'width' => 12,
            'height' => 4,
        ]);
    }

    private function widgetRow(): ?array
    {
        return collect($this->getJson('/api/v1/dashboard/layout?rich=1')->assertOk()->json('data'))
            ->firstWhere('key', 'approvals.pending');
    }

    public function test_the_widget_is_seeded_as_a_worklist_open_to_every_role(): void
    {
        $this->assertDatabaseHas('dashboard_widgets', [
            'key' => 'approvals.pending',
            'render_kind' => 'table',
            // Ungated on purpose: the resolver scopes to the caller's own
            // queue, so there is nothing role-specific to gate.
            'permission' => null,
            'link_path' => '/approvals',
        ]);
    }

    public function test_rows_are_oldest_first_with_the_document_number(): void
    {
        $user = $this->actingAsRole('department_head');
        $this->putOnLayout($user);

        $newest = $this->pendingApproval('department_head', 2);
        $oldest = $this->pendingApproval('department_head', 72);

        $data = $this->widgetRow()['data'];

        $this->assertSame(2, $data['total_count']);
        $this->assertSame(
            [$oldest->pr_number, $newest->pr_number],
            array_column($data['rows'], 'number'),
            'the queue must lead with what has waited longest',
        );
        $this->assertSame(72, $data['rows'][0]['waiting_hours']);
        $this->assertSame('pr', $data['rows'][0]['type']);
    }

    /** Another role's queue is not this caller's work. */
    public function test_the_worklist_is_scoped_to_the_callers_own_queue(): void
    {
        $user = $this->actingAsRole('department_head');
        $this->putOnLayout($user);

        $mine = $this->pendingApproval('department_head', 5);
        $this->pendingApproval('finance_officer', 5);

        $data = $this->widgetRow()['data'];

        $this->assertSame(1, $data['total_count']);
        $this->assertSame([$mine->pr_number], array_column($data['rows'], 'number'));
    }

    /**
     * An empty queue is a real state, but an empty table reads as a broken
     * widget — so the provider yields and the scalar zero renders instead.
     */
    public function test_an_empty_queue_falls_back_to_the_scalar(): void
    {
        $user = $this->actingAsRole('department_head');
        $this->putOnLayout($user);

        $this->assertNull($this->widgetRow()['data']);
    }
}
