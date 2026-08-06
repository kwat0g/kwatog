<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\RolloutHealthService;
use Illuminate\Console\Command;

class ReportRolloutHealth extends Command
{
    protected $signature = 'operations:rollout-health {--json : Emit machine-readable JSON}';

    protected $description = 'Report quality-plan, QC-trigger, scanner, and Action Center rollout health';

    public function handle(RolloutHealthService $health, SettingsService $settings): int
    {
        $roles = array_values(array_filter((array) $settings->get('system.automation.actor_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        $user = User::query()->where('is_active', true)
            ->whereHas('role', fn ($role) => $role->whereIn('slug', $roles))->first();
        if (! $user) {
            $this->error('No active system administrator is available for permission-aware health metrics.');

            return self::FAILURE;
        }
        $data = $health->summary($user);
        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Operational rollout status: '.strtoupper($data['status']));
        $this->table(['Metric', 'Value'], [
            ['Quality-plan coverage', $data['quality_plans']['coverage_percent'].'%'],
            ['Missing plans', count($data['quality_plans']['missing'])],
            ['Pending GRNs missing QC', $data['qc_triggers']['pending_grns_without_inspection']],
            ['Failed inspections (24h)', $data['qc_triggers']['failed_inspections_24h']],
            ['Scanner recognition (24h)', $data['scanner']['recognition_rate'].'%'],
            ['Overdue actions', $data['actions']['overdue']],
        ]);

        return self::SUCCESS;
    }
}
