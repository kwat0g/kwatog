<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on P67 cancel: NcrService::cancel evaluates the terminal guard
 * on the *passed* model and writes outside any transaction. A close that commits
 * first is then flipped back to `cancelled` by the stale cancel — the NCR ends
 * in the wrong terminal state after already being closed.
 */
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
                'ncr_id'       => $ncr->id,
                'action_type'  => $type->value,
                'description'  => 'x',
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
}
