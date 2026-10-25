<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Events\UserNotificationCreated;
use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Tests\TestCase;

class ApprovalSubmitterOutcomeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Event::fake([UserNotificationCreated::class]);
    }

    public function test_outcome_notice_requires_record_view_access(): void
    {
        $submitter = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
        $this->assertFalse(ApprovalTypeRegistry::userCanViewRecord($submitter, Asset::class, 101));
        $this->notifyOutcome($submitter, 101);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $submitter->id,
            'type' => 'approval.rejected',
        ]);

        $visibleSubmitter = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $this->assertTrue(ApprovalTypeRegistry::userCanViewRecord($visibleSubmitter, Asset::class, 102));
        $this->notifyOutcome($visibleSubmitter, 102);

        $notice = DB::table('notifications')
            ->where('notifiable_id', $visibleSubmitter->id)
            ->where('type', 'approval.rejected')
            ->first();
        $this->assertNotNull($notice);
        $this->assertSame('/assets/'.app('hashids')->encode(102), json_decode((string) $notice->data, true)['link_to']);
    }

    private function notifyOutcome(User $submitter, int $assetId): void
    {
        $asset = new Asset;
        $asset->setRawAttributes([
            'id' => $assetId,
            'asset_code' => "AST-{$assetId}",
            'disposal_requested_by' => $submitter->id,
        ], true);
        $asset->exists = true;

        $method = new ReflectionMethod(ApprovalService::class, 'notifySubmitter');
        $method->setAccessible(true);
        $method->invoke(
            app(ApprovalService::class),
            $asset,
            new ApprovalRecord(['attempt' => 1]),
            'approval.rejected',
            'Approval rejected',
        );
    }
}
