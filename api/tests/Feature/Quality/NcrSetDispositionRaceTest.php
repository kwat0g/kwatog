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

/**
 * P01-01 shape on P67 setDisposition: the terminal guard ran on the *passed*
 * model with no transaction and no lock, and the method unconditionally sets
 * status = in_progress. A stale disposition landing after a concurrent close
 * flips the Closed NCR back to InProgress — the terminal state is undone.
 */
class NcrSetDispositionRaceTest extends TestCase
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

    public function test_stale_disposition_cannot_flip_a_closed_ncr_back_to_in_progress(): void
    {
        $by = $this->user();
        $ncr = $this->closableNcr();

        // Closer and dispositioner each fetched the row while it was open.
        $closer = NonConformanceReport::find($ncr->id);
        $dispositioner = NonConformanceReport::find($ncr->id);

        // Closer commits first.
        app(NcrService::class)->close($closer, $by);
        $this->assertSame(NcrStatus::Closed, $ncr->refresh()->status);

        // Concurrent stale dispositioner still sees `open` in memory.
        try {
            app(NcrService::class)->setDisposition($dispositioner, NcrDisposition::UseAsIs->value, null, null);
            $this->fail('A stale disposition must not undo a closed NCR.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('already closed', strtolower($e->getMessage()));
        }

        $this->assertSame(NcrStatus::Closed, $ncr->refresh()->status);
    }
}
