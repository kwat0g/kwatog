<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\ControlledDocument;
use App\Modules\Quality\Services\DocumentReviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DocumentReviewExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function recipientUsers(): array
    {
        $admin = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        $qc = User::factory()->create([
            'role_id'   => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
        return [$admin, $qc];
    }

    public function test_doc_within_review_window_does_not_fire(): void
    {
        $this->recipientUsers();

        $doc = ControlledDocument::create([
            'code' => 'SOP-RV-100', 'title' => 'Within Window', 'category' => 'sop',
            'assignee_role' => 'qc_inspector', 'is_active' => true,
            'review_interval_months' => 12,
        ]);
        // last_reviewed_at is service-managed, not fillable.
        $doc->forceFill(['last_reviewed_at' => now()->subMonths(2)])->save();

        $svc = $this->app->make(DocumentReviewService::class);
        $r = $svc->check();

        $this->assertSame(0, $r['alerts_sent']);
        $this->assertSame(
            0,
            DB::table('notifications')->where('type', 'document.review_due')->count()
        );
        $this->assertNull($doc->fresh()->last_review_alert_at);
    }

    public function test_overdue_doc_fires_and_stamps_alert_at(): void
    {
        $this->recipientUsers();

        $doc = ControlledDocument::create([
            'code' => 'SOP-RV-200', 'title' => 'Overdue', 'category' => 'sop',
            'assignee_role' => 'qc_inspector', 'is_active' => true,
            'review_interval_months' => 6,
        ]);
        $doc->forceFill(['last_reviewed_at' => now()->subMonths(8)])->save();

        $svc = $this->app->make(DocumentReviewService::class);
        $r = $svc->check();

        $this->assertSame(1, $r['alerts_sent']);
        $this->assertNotNull($doc->fresh()->last_review_alert_at);

        // notifications table receives one row per recipient (admin + qc).
        $this->assertSame(2, DB::table('notifications')
            ->where('type', 'document.review_due')->count());
    }

    public function test_same_day_rerun_does_not_refire(): void
    {
        $this->recipientUsers();

        $doc = ControlledDocument::create([
            'code' => 'SOP-RV-300', 'title' => 'Idempotent', 'category' => 'sop',
            'assignee_role' => 'qc_inspector', 'is_active' => true,
            'review_interval_months' => 6,
        ]);
        $doc->forceFill(['last_reviewed_at' => now()->subMonths(8)])->save();

        $svc = $this->app->make(DocumentReviewService::class);
        $first = $svc->check();
        $second = $svc->check();

        $this->assertSame(1, $first['alerts_sent']);
        $this->assertSame(0, $second['alerts_sent']);
        $this->assertSame(2, DB::table('notifications')
            ->where('type', 'document.review_due')->count());
    }
}
