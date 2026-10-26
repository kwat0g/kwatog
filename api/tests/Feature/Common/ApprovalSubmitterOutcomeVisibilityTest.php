<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Events\UserNotificationCreated;
use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalService;
use App\Common\Support\ApprovalTypeRegistry;
use App\Modules\Assets\Enums\AssetCategory;
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
        $hiddenAsset = $this->asset($submitter, 'AST-NOTIFY-101');
        $this->assertFalse(ApprovalTypeRegistry::userCanViewRecord($submitter, Asset::class, $hiddenAsset->id));
        $this->notifyOutcome($submitter, $hiddenAsset);

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $submitter->id,
            'type' => 'approval.rejected',
        ]);

        $visibleSubmitter = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $visibleAsset = $this->asset($visibleSubmitter, 'AST-NOTIFY-102');
        $this->assertTrue(ApprovalTypeRegistry::userCanViewRecord($visibleSubmitter, Asset::class, $visibleAsset->id));
        $this->notifyOutcome($visibleSubmitter, $visibleAsset);

        $notice = DB::table('notifications')
            ->where('notifiable_id', $visibleSubmitter->id)
            ->where('type', 'approval.rejected')
            ->first();
        $this->assertNotNull($notice);
        $this->assertSame('/assets/'.$visibleAsset->hash_id, json_decode((string) $notice->data, true)['link_to']);
    }

    private function asset(User $submitter, string $assetCode): Asset
    {
        return Asset::create([
            'asset_code' => $assetCode,
            'name' => 'Notification access asset',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-15',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'disposal_requested_by' => $submitter->id,
        ]);
    }

    private function notifyOutcome(User $submitter, Asset $asset): void
    {
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
