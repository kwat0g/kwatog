<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Quality\Enums\EffectivenessStatus;
use App\Modules\Quality\Enums\NcrActionType;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Quality\Enums\NcrStatus;
use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\EffectivenessService;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NcrCapaEffectivenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role = 'system_admin'): User
    {
        $roleId = Role::query()->where('slug', $role)->value('id')
            ?? Role::query()->firstOrCreate(
                ['slug' => $role],
                ['name' => ucwords(str_replace('_', ' ', $role))],
            )->id;

        return User::factory()->create([
            'role_id'   => $roleId,
            'is_active' => true,
        ]);
    }

    /** @return array{0: NonConformanceReport, 1: User} */
    private function closedNcr(): array
    {
        $by = $this->user();
        $ncr = NonConformanceReport::factory()->create([
            'created_by' => $by->id,
            'status'     => NcrStatus::Open->value,
        ]);
        $ncr->forceFill(['disposition' => NcrDisposition::UseAsIs->value])->save();

        foreach ([NcrActionType::Containment, NcrActionType::Corrective, NcrActionType::Preventive] as $type) {
            NcrAction::create([
                'ncr_id'       => $ncr->id,
                'action_type'  => $type->value,
                'description'  => "{$type->value} action",
                'performed_by' => $by->id,
                'owner_id'     => $by->id,
                'performed_at' => now(),
            ]);
        }

        return [app(NcrService::class)->close($ncr->fresh(), $by), $by];
    }

    public function test_close_schedules_only_corrective_and_preventive_actions(): void
    {
        [$closed] = $this->closedNcr();

        $actions = NcrAction::query()->where('ncr_id', $closed->id)->get()->keyBy('action_type');

        $this->assertSame(
            EffectivenessStatus::PendingVerification,
            $actions[NcrActionType::Corrective->value]->effectiveness_status,
        );
        $this->assertSame(
            EffectivenessStatus::PendingVerification,
            $actions[NcrActionType::Preventive->value]->effectiveness_status,
        );
        $this->assertNull($actions[NcrActionType::Containment->value]->effectiveness_status);
        $this->assertSame(
            EffectivenessStatus::PendingVerification,
            $closed->fresh()->effectiveness_status,
        );
    }

    public function test_containment_cannot_be_verified(): void
    {
        [$closed, $by] = $this->closedNcr();
        $containment = NcrAction::query()
            ->where('ncr_id', $closed->id)
            ->where('action_type', NcrActionType::Containment->value)
            ->firstOrFail();
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        app(EffectivenessService::class)->verifyAction(
            $containment,
            $by,
            EffectivenessStatus::Effective,
            'Containment is not a CAPA action.',
        );
    }

    public function test_terminal_verdict_cannot_be_overwritten(): void
    {
        [$closed, $by] = $this->closedNcr();
        $corrective = NcrAction::query()
            ->where('ncr_id', $closed->id)
            ->where('action_type', NcrActionType::Corrective->value)
            ->firstOrFail();
        $service = app(EffectivenessService::class);

        $service->verifyAction(
            $corrective,
            $by,
            EffectivenessStatus::Effective,
            'First verification.',
        );

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $service->verifyAction(
            $corrective->fresh(),
            $by,
            EffectivenessStatus::Ineffective,
            'Attempted overwrite.',
        );
    }

    public function test_all_capa_verdicts_roll_up_to_effective(): void
    {
        [$closed, $by] = $this->closedNcr();
        $actions = NcrAction::query()->where('ncr_id', $closed->id)->get()->keyBy('action_type');
        $service = app(EffectivenessService::class);

        $service->verifyAction(
            $actions[NcrActionType::Corrective->value],
            $by,
            EffectivenessStatus::Effective,
            'Corrective action held during verification.',
        );
        $service->verifyAction(
            $actions[NcrActionType::Preventive->value],
            $by,
            EffectivenessStatus::NotApplicable,
            'No separate preventive action is required for this defect class.',
        );

        $updated = $closed->fresh();
        $this->assertSame(EffectivenessStatus::Effective, $updated->effectiveness_status);
        $this->assertNotNull($updated->effectiveness_closed_at);
    }

    public function test_due_notifications_are_idempotent_per_action_and_due_date(): void
    {
        [$closed, $by] = $this->closedNcr();
        NcrAction::query()
            ->where('ncr_id', $closed->id)
            ->whereIn('action_type', [NcrActionType::Corrective->value, NcrActionType::Preventive->value])
            ->update(['next_effectiveness_check_at' => now()->subDay()->toDateString()]);

        $service = app(EffectivenessService::class);
        $service->notifyOverdueChecks();
        $service->notifyOverdueChecks();

        $this->assertSame(
            2,
            DB::table('notifications')
                ->where('type', 'effectiveness_due')
                ->where('notifiable_id', $by->id)
                ->count(),
        );
        $this->assertSame(
            2,
            DB::table('ncr_effectiveness_notifications')->count(),
        );
    }
}
