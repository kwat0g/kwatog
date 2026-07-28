<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Console\Command;

class RolloutBaselineQualityPlans extends Command
{
    protected $signature = 'quality:plans:rollout
        {--apply : Persist plans; otherwise run as a dry-run}
        {--critical-only : Limit rollout to critical eligible items}';

    protected $description = 'Idempotently create conservative baseline incoming quality plans for eligible items';

    public function handle(ItemQualityPlanService $plans): int
    {
        $actor = User::query()->where('is_active', true)
            ->whereHas('role', fn ($role) => $role->where('slug', 'system_admin'))
            ->orderBy('id')->first();
        if (! $actor) {
            $this->error('No active system administrator is available to own generated plan revisions.');

            return self::FAILURE;
        }

        $query = Item::query()->active()
            ->whereIn('item_type', config('quality_rollout.eligible_item_types', ['raw_material']))
            ->whereDoesntHave('qualityPlans', fn ($plan) => $plan->effective());
        if ($this->option('critical-only')) {
            $query->where('is_critical', true);
        }
        $items = $query->orderBy('code')->get();

        if ($items->isEmpty()) {
            $this->info('Every eligible item already has an effective quality plan.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $rows = [];
        foreach ($items as $item) {
            $template = str_contains(strtolower($item->name.' '.$item->code), 'resin') ? 'resin' : 'general';
            $rows[] = [$item->code, $item->name, $template, $apply ? 'created' : 'would create'];
            if (! $apply) {
                continue;
            }

            $plans->createRevision($item, [
                'sampling_method' => 'fixed',
                'fixed_sample_size' => (int) config('quality_rollout.fixed_sample_size', 3),
                'parameters' => config("quality_rollout.templates.{$template}"),
                'effective_from' => now()->toDateString(),
                'notes' => 'Baseline rollout plan. QC must review and publish a tailored revision where drawing, COA, or supplier controls require tighter limits.',
            ], $actor);
        }

        $this->table(['Item', 'Name', 'Template', 'Result'], $rows);
        $this->newLine();
        $this->info($apply
            ? "Created {$items->count()} baseline plan(s); existing effective plans were untouched."
            : "Dry-run: {$items->count()} plan(s) would be created. Re-run with --apply to persist.");

        return self::SUCCESS;
    }
}
