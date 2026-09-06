<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assets\Enums\AssetCategory;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on the assets ledger: AssetService::dispose guarded the *passed*
 * model outside the transaction with no locked re-read inside. Two concurrent
 * disposals both observe `active` and each posts its own disposal journal entry
 * — the disposal JE is double-booked (cash credited twice, PPE removed twice).
 *
 * AS-03 update: disposal is approval-gated now, so the double-posting surface
 * moved to the two-phase flow. The pins below cover its equivalents: a second
 * request on a stale snapshot (pending or disposed), a second approval after
 * the chain closed, and the invariant they both protect — exactly one JE.
 */
class AssetDisposeDoublePostingRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            ChartOfAccountsSeeder::class,
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            WorkflowSeeder::class,
        ]);
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
        ]);
    }

    private function asset(): Asset
    {
        return Asset::create([
            'asset_code'               => 'AST-RACE-'.substr(uniqid(), -6),
            'name'                     => 'Race CNC Machine',
            'category'                 => AssetCategory::Equipment->value,
            'acquisition_date'         => '2026-01-15',
            'acquisition_cost'         => 100000,
            'useful_life_years'        => 5,
            'salvage_value'            => 0,
            'accumulated_depreciation' => 20000,
            'status'                   => AssetStatus::Active->value,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function requestDisposal(Asset $asset, array $data, User $by): Asset
    {
        return app(AssetService::class)->requestDisposal($asset, array_merge([
            'disposal_amount' => 90000,
            'disposed_date'   => '2026-08-13',
            'remarks'         => 'Asset sold.',
        ], $data), $by);
    }

    private function disposeViaApproval(Asset $asset): void
    {
        $this->requestDisposal($asset, [], $this->user('finance_officer'));
        app(AssetService::class)->approveDisposal($asset, $this->user('finance_officer'));
        app(AssetService::class)->approveDisposal($asset, $this->user('system_admin'));
    }

    public function test_stale_second_dispose_is_blocked_and_posts_single_je(): void
    {
        $by = $this->user('finance_officer');
        $asset = $this->asset();

        // Both "concurrent" disposers fetched the row while it was active.
        $disposerA = Asset::find($asset->id);
        $disposerB = Asset::find($asset->id);

        $this->disposeViaApproval($disposerA);

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('reference_type', Asset::class)
                ->where('reference_id', $asset->id)
                ->count(),
            'Exactly one disposal JE must be posted for the asset.'
        );

        try {
            $this->requestDisposal($disposerB, [], $by);
            $this->fail('A stale second dispose must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already disposed', strtolower($e->getMessage()));
        }

        // And a second approval after the chain closed finds the asset
        // already disposed — the status guard answers first.
        try {
            app(AssetService::class)->approveDisposal($disposerB, $this->user('system_admin'));
            $this->fail('A second approval after execution must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already disposed', strtolower($e->getMessage()));
        }

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('reference_type', Asset::class)
                ->where('reference_id', $asset->id)
                ->count(),
            'The stale dispose must not post a second journal entry.'
        );
    }

    public function test_two_pending_disposal_requests_cannot_coexist(): void
    {
        $by = $this->user('finance_officer');
        $asset = $this->asset();

        $this->requestDisposal($asset, [], $by);

        // A concurrent requester holding the same stale `active` snapshot.
        $stale = Asset::find($asset->id);
        try {
            $this->requestDisposal($stale, [], $by);
            $this->fail('A second request while one is pending must be rejected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already pending approval', strtolower($e->getMessage()));
        }

        // The single surviving request executes exactly once.
        app(AssetService::class)->approveDisposal($stale, $this->user('finance_officer'));
        app(AssetService::class)->approveDisposal($stale, $this->user('system_admin'));
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('reference_type', Asset::class)
                ->where('reference_id', $asset->id)
                ->count(),
            'The overlapping request must not produce a second journal entry.'
        );
    }
}
