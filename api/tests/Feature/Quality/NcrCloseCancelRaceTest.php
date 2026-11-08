<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Covers the row-locked NCR terminal guards and the stale-action API response. */
class NcrCloseCancelRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('quality.ncr.replacement_work_order_priority', 7);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function closableNcr(): NonConformanceReport
    {
        $ncr = NonConformanceReport::factory()->create([
            'affected_quantity' => 10,
        ]);
        $ncr->forceFill(['disposition' => NcrDisposition::UseAsIs->value])->save();

        foreach ([NcrActionType::Corrective, NcrActionType::Preventive] as $type) {
            NcrAction::create([
                'ncr_id' => $ncr->id,
                'action_type' => $type->value,
                'description' => 'x',
                'performed_by' => $ncr->created_by,
                'performed_at' => now(),
            ]);
        }

        return $ncr;
    }

    public function test_stale_cancel_cannot_flip_a_closed_ncr(): void
    {
        $by = $this->user();
        $ncr = $this->closableNcr();

        // Closer and canceller each fetched the row while it was open.
        $closer = NonConformanceReport::find($ncr->id);
        $canceller = NonConformanceReport::find($ncr->id);

        // Closer commits first.
        app(NcrService::class)->close($closer, $by);
        $this->assertSame(NcrStatus::Closed, $ncr->refresh()->status);

        // Concurrent stale canceller still sees `open` in memory.
        try {
            app(NcrService::class)->cancel($canceller, 'sent by mistake', $by);
            $this->fail('A stale cancel must not flip an already-closed NCR.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already closed', strtolower($e->getMessage()));
        }

        $this->assertSame(NcrStatus::Closed, $ncr->refresh()->status);
    }

    public function test_stale_close_and_cancel_requests_return_conflict(): void
    {
        $by = $this->user();
        $ncr = $this->closableNcr();

        app(NcrService::class)->close($ncr->fresh(), $by);

        $this->actingAs($by, 'sanctum')
            ->postJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/close')
            ->assertConflict()
            ->assertJsonPath('code', 'ncr_state_conflict');

        $this->patchJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/disposition', [
            'disposition' => NcrDisposition::UseAsIs->value,
        ])
            ->assertConflict()
            ->assertJsonPath('code', 'ncr_state_conflict');

        $this->postJson('/api/v1/quality/ncrs/'.$ncr->hash_id.'/cancel', [
            'reason' => 'Stale cancellation request.',
        ])
            ->assertConflict()
            ->assertJsonPath('code', 'ncr_state_conflict');
    }
}
