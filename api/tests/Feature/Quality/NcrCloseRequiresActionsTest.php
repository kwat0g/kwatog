<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

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
use RuntimeException;
use Tests\TestCase;

class NcrCloseRequiresActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function ncrWithDisposition(): NonConformanceReport
    {
        $ncr = NonConformanceReport::factory()->create([
            'status' => NcrStatus::Open->value,
        ]);
        $ncr->forceFill(['disposition' => NcrDisposition::UseAsIs->value])->save();
        return $ncr;
    }

    private function addAction(NonConformanceReport $ncr, NcrActionType $type, User $by): void
    {
        NcrAction::create([
            'ncr_id'        => $ncr->id,
            'action_type'   => $type->value,
            'description'   => "Test {$type->value} action",
            'performed_by'  => $by->id,
            'performed_at'  => now(),
        ]);
    }

    public function test_close_rejects_when_no_actions_recorded(): void
    {
        $by = $this->user();
        $ncr = $this->ncrWithDisposition();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Corrective');

        app(NcrService::class)->close($ncr, $by);
    }

    public function test_close_rejects_when_only_corrective_recorded(): void
    {
        $by = $this->user();
        $ncr = $this->ncrWithDisposition();
        $this->addAction($ncr, NcrActionType::Corrective, $by);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Preventive');

        app(NcrService::class)->close($ncr, $by);
    }

    public function test_close_rejects_when_only_preventive_recorded(): void
    {
        $by = $this->user();
        $ncr = $this->ncrWithDisposition();
        $this->addAction($ncr, NcrActionType::Preventive, $by);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Corrective');

        app(NcrService::class)->close($ncr, $by);
    }

    public function test_close_succeeds_with_both_actions(): void
    {
        $by = $this->user();
        $ncr = $this->ncrWithDisposition();
        $this->addAction($ncr, NcrActionType::Corrective, $by);
        $this->addAction($ncr, NcrActionType::Preventive, $by);

        $closed = app(NcrService::class)->close($ncr, $by);

        $this->assertSame(NcrStatus::Closed->value, $closed->status->value);
    }
}
