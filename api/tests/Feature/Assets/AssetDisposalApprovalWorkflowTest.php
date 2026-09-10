<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Models\ApprovalRecord;
use App\Common\Services\ApprovalBoardService;
use App\Common\Services\ApprovalService;
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
 * AS-03 — disposal must be an approval-gated two-phase action.
 *
 * Before this, `assets.dispose` alone posted the derecognition JE: no
 * ApprovalService::submit, no approval_records row, no second pair of eyes
 * on gain/loss recognition, while WorkflowSeeder carried a RESERVED
 * `asset_disposal` definition nothing called. The pins here:
 *
 *   a) request → full chain approval → JE + Disposed (execution unchanged);
 *   b) rejection leaves the asset untouched (active, no JE);
 *   c) each step role sees the pending card and can act on it;
 *   d) `assets.dispose` alone no longer disposes directly;
 *   e) requester-only cancellation, SoD, and the single-pending-request guard.
 */
class AssetDisposalApprovalWorkflowTest extends TestCase
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

    /** @param array<string, mixed> $overrides */
    private function asset(array $overrides = []): Asset
    {
        return Asset::create(array_merge([
            'asset_code' => 'AST-DA-'.substr(uniqid(), -6),
            'name' => 'Disposal approval asset',
            'category' => AssetCategory::Equipment->value,
            'acquisition_date' => '2026-01-15',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'accumulated_depreciation' => '3000.00',
            'status' => AssetStatus::Active->value,
        ], $overrides));
    }

    /** @param array<string, mixed> $data */
    private function requestDisposal(Asset $asset, array $data = [], ?User $requester = null): Asset
    {
        return app(AssetService::class)->requestDisposal($asset, array_merge([
            'disposal_amount' => '5000.00',
            'disposed_date' => '2026-08-13',
            'remarks' => 'Sold — replaced by newer machine',
        ], $data), $requester ?? $this->user('finance_officer'));
    }

    /** Approve every step of the seeded chain with distinct holders. */
    private function approveFullChain(Asset $asset): void
    {
        $svc = app(AssetService::class);
        $svc->approveDisposal($asset, $this->user('finance_officer'));
        $svc->approveDisposal($asset, $this->user('system_admin'));
    }

    private function disposalJeCount(Asset $asset): int
    {
        return JournalEntry::query()
            ->where('reference_type', Asset::class)
            ->where('reference_id', $asset->getKey())
            ->count();
    }

    public function test_full_chain_approval_disposes_the_asset_and_posts_the_je(): void
    {
        $asset = $this->asset();
        $requester = $this->user('finance_officer');

        $this->requestDisposal($asset, [], $requester);

        // Nothing disposed, nothing journalised at request time.
        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
        $this->assertSame(0, $this->disposalJeCount($asset));
        $this->assertSame('5000.00', (string) $asset->fresh()->disposal_request_amount);
        $this->assertSame($requester->id, $asset->fresh()->disposal_requested_by);

        $this->approveFullChain($asset);

        $fresh = $asset->fresh();
        $this->assertSame(AssetStatus::Disposed, $fresh->status);
        $this->assertSame('2026-08-13', $fresh->disposed_date?->toDateString());
        $this->assertSame('5000.00', (string) $fresh->disposal_amount);
        $this->assertSame('Sold — replaced by newer machine', $fresh->disposal_reason);
        // Execution clears the proposal columns in the same save.
        $this->assertNull($fresh->disposal_request_amount);
        $this->assertNull($fresh->disposal_requested_by);

        // The JE is the same shape the direct path used to post: proceeds
        // debited, accumulated depreciation reversed, cost removed, loss
        // booked for the difference against book value (12000 - 3000 - 5000).
        $je = JournalEntry::with('lines')->where('reference_type', Asset::class)
            ->where('reference_id', $asset->getKey())->sole();
        $this->assertSame(1, $this->disposalJeCount($asset));
        $this->assertSame(4, $je->lines->count());

        $chain = app(ApprovalService::class)->currentChain($fresh);
        $this->assertCount(2, $chain);
        $this->assertTrue($chain->every(fn (ApprovalRecord $r) => $r->action === 'approved'));
    }

    public function test_partial_approval_keeps_the_request_pending(): void
    {
        $asset = $this->asset();
        $this->requestDisposal($asset);

        app(AssetService::class)->approveDisposal($asset, $this->user('finance_officer'));

        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
        $this->assertSame(0, $this->disposalJeCount($asset));

        $next = app(ApprovalService::class)->nextStep($asset->fresh());
        $this->assertNotNull($next);
        $this->assertSame('system_admin', $next->role_slug);
    }

    public function test_rejection_leaves_the_asset_untouched_and_a_new_request_can_be_made(): void
    {
        $asset = $this->asset();
        $this->requestDisposal($asset);

        app(AssetService::class)->rejectDisposal($asset, $this->user('finance_officer'), 'Proceeds far below book value');

        $fresh = $asset->fresh();
        $this->assertSame(AssetStatus::Active, $fresh->status);
        $this->assertSame(0, $this->disposalJeCount($asset));
        $this->assertNull($fresh->disposal_request_amount);
        $this->assertNull($fresh->disposal_requested_by);

        // Later steps are skipped; nothing stays actionable.
        $this->assertNull(app(ApprovalService::class)->nextStep($fresh));

        // The asset is not poisoned: a corrected request can be raised.
        $this->requestDisposal($asset, ['disposal_amount' => '9500.00']);
        $this->assertSame('9500.00', (string) $asset->fresh()->disposal_request_amount);
    }

    public function test_each_step_role_sees_the_pending_card_and_can_act(): void
    {
        $asset = $this->asset();
        $this->requestDisposal($asset);

        $board = app(ApprovalBoardService::class);
        $finance = $this->user('finance_officer');
        $admin = $this->user('system_admin');
        $employee = $this->user('employee');

        // Step 1 (finance_officer) pending: finance sees an actionable card,
        // admin sees it as awaiting their step, employee (no assets.view)
        // sees nothing at all.
        $financeBoard = $board->board($finance);
        $this->assertSame([$asset->asset_code], array_map(fn (array $c) => $c['number'], $financeBoard['my_action']));
        $this->assertSame('5000.00', $financeBoard['my_action'][0]['amount']);
        $this->assertSame('/assets/'.$asset->hash_id, $financeBoard['my_action'][0]['link']);
        $this->assertSame([], $board->board($employee)['my_action']);
        $this->assertSame([], $board->board($employee)['awaiting_others']);

        // Step 1 approved by its role holder — the board re-read proves the
        // acting user was the one the card was for.
        app(AssetService::class)->approveDisposal($asset, $finance);

        $adminBoard = $board->board($admin);
        $this->assertSame([$asset->asset_code], array_map(fn (array $c) => $c['number'], $adminBoard['my_action']));
        $this->assertSame([], $board->board($finance)['my_action']);

        // Step 2 approved by its role holder: execution follows.
        app(AssetService::class)->approveDisposal($asset, $admin);
        $this->assertSame(AssetStatus::Disposed, $asset->fresh()->status);
        $this->assertSame(1, $this->disposalJeCount($asset));
    }

    public function test_dispose_permission_alone_no_longer_disposes_directly(): void
    {
        $asset = $this->asset();
        $finance = $this->user('finance_officer');

        // The old single-permission path: POST /dispose with assets.dispose.
        $this->actingAs($finance)
            ->postJson("/api/v1/assets/{$asset->hash_id}/dispose", [
                'disposal_amount' => '5000.00',
                'disposed_date' => '2026-08-13',
                'remarks' => 'Sold — replaced by newer machine',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
        $this->assertSame(0, $this->disposalJeCount($asset));
        $this->assertSame(2, ApprovalRecord::query()
            ->where('approvable_type', Asset::class)
            ->where('approvable_id', $asset->getKey())
            ->where('action', 'pending')
            ->where('is_current', true)
            ->count(), 'The endpoint must open the approval chain, not dispose.');

        // And the service arm refuses an unapproved disposal even when called
        // directly — the JE cannot post without the chain, whatever the route.
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('requires an approved disposal request');
        app(AssetService::class)->dispose($asset->fresh(), [
            'disposal_amount' => '5000.00',
            'disposed_date' => '2026-08-13',
            'remarks' => 'Sold — replaced by newer machine',
        ], $this->user('system_admin'));
    }

    public function test_requester_cannot_approve_their_own_request(): void
    {
        $asset = $this->asset();
        $requester = $this->user('finance_officer');
        $this->requestDisposal($asset, [], $requester);

        $response = $this->actingAs($requester)
            ->postJson("/api/v1/assets/{$asset->hash_id}/dispose/approve", ['remarks' => 'Looks fine']);

        $response->assertStatus(403);
        $this->assertSame('You cannot act on a record you submitted.', $response->json('message'));
        $this->assertSame(AssetStatus::Active, $asset->fresh()->status);
    }

    public function test_wrong_role_cannot_approve_the_step(): void
    {
        $asset = $this->asset();
        $this->requestDisposal($asset);

        // system_admin holds every permission, so the middleware lets this
        // through; its role slug is not the step's finance_officer.
        $response = $this->actingAs($this->user('system_admin'))
            ->postJson("/api/v1/assets/{$asset->hash_id}/dispose/approve");

        $response->assertStatus(403);
        $this->assertSame("Only users with role 'finance_officer' can approve this step.", $response->json('message'));
    }

    public function test_requester_can_cancel_but_nobody_else(): void
    {
        $asset = $this->asset();
        $requester = $this->user('finance_officer');
        $this->requestDisposal($asset, [], $requester);

        $this->actingAs($this->user('system_admin'))
            ->postJson("/api/v1/assets/{$asset->hash_id}/dispose/cancel")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Only the requester can cancel a pending disposal request.');

        $this->actingAs($requester)
            ->postJson("/api/v1/assets/{$asset->hash_id}/dispose/cancel")
            ->assertOk();

        $fresh = $asset->fresh();
        $this->assertSame(AssetStatus::Active, $fresh->status);
        $this->assertSame(0, $this->disposalJeCount($asset));
        $this->assertNull($fresh->disposal_requested_by);
        $this->assertSame(0, ApprovalRecord::query()
            ->where('approvable_id', $asset->getKey())
            ->where('is_current', true)
            ->whereIn('action', ['pending', 'skipped'])
            ->count(), 'Cancellation must supersede the open steps.');

        // The asset can be re-requested after cancellation.
        $this->requestDisposal($asset);
        $this->assertNotNull(app(ApprovalService::class)->nextStep($asset->fresh()));
    }

    public function test_a_second_request_while_one_is_pending_is_refused(): void
    {
        $asset = $this->asset();
        $this->requestDisposal($asset);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('already pending approval');
        $this->requestDisposal($asset);
    }

    /**
     * Drift guard (PS-01 defect class, mirrors the purchase_request pin):
     * every seeded asset_disposal step role must hold both the approve-route
     * permission and the module read, or the chain stalls at that step.
     */
    public function test_every_seeded_step_role_can_reach_the_approve_route_and_board(): void
    {
        $workflow = \App\Common\Models\WorkflowDefinition::query()
            ->where('workflow_type', 'asset_disposal')
            ->where('is_active', true)
            ->firstOrFail();

        $this->assertNotEmpty($workflow->steps);

        foreach ($workflow->steps as $step) {
            $slug = (string) $step['role'];
            $user = $this->user($slug);

            $this->assertTrue(
                $user->hasPermission('assets.dispose.approve'),
                "Chain step {$step['order']} routes to '{$slug}', which cannot reach the approve route.",
            );
            $this->assertTrue(
                $user->hasPermission('assets.view'),
                "Chain step {$step['order']} routes to '{$slug}', which cannot open the asset it must dispose.",
            );
        }
    }

    public function test_a_refused_execution_keeps_the_request_pending_for_resolution(): void
    {
        // Zero cost + zero proceeds + never depreciated: nothing to
        // journalise. The final approval must refuse (execute throws) and
        // roll back its own approval step, leaving the request pending so it
        // can be rejected or cancelled — never half-disposed.
        $asset = $this->asset(['acquisition_cost' => '0.00', 'accumulated_depreciation' => '0.00']);
        $this->requestDisposal($asset, ['disposal_amount' => '0.00']);

        app(AssetService::class)->approveDisposal($asset, $this->user('finance_officer'));

        try {
            app(AssetService::class)->approveDisposal($asset, $this->user('system_admin'));
            $this->fail('The final approval must refuse a disposal with nothing to journalise.');
        } catch (\App\Common\Exceptions\BusinessRuleException $e) {
            $this->assertStringContainsString('no cost, proceeds or accumulated depreciation', $e->getMessage());
        }

        $fresh = $asset->fresh();
        $this->assertSame(AssetStatus::Active, $fresh->status);
        $this->assertSame(0, $this->disposalJeCount($asset));
        $this->assertNotNull(app(ApprovalService::class)->nextStep($fresh), 'The refused execution must not leave the chain closed.');
    }
}
