<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Database\Seeder;

class DemoItemQualityPlanSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('app.seed_demo_data')) {
            return;
        }

        $settings = app(SettingsService::class);
        $actorRoles = array_values(array_filter((array) $settings->get('system.automation.actor_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
        $actor = User::query()->where('is_active', true)
            ->whereHas('role', fn ($role) => $role->whereIn('slug', $actorRoles))
            ->orderBy('id')->first();
        $eligibleTypes = $settings->get('quality.rollout.eligible_item_types', []);

        if ($actor === null || ! is_array($eligibleTypes) || $eligibleTypes === []) {
            throw new BusinessRuleException('Demo quality-plan seeding requires an automation actor and eligible item types.');
        }

        $items = Item::query()->active()->whereIn('item_type', $eligibleTypes)
            ->whereDoesntHave('qualityPlans', fn ($plan) => $plan->effective())
            ->orderBy('code')->get();

        foreach ($items as $item) {
            $template = str_contains(strtolower($item->name.' '.$item->code), 'resin') ? 'resin' : 'general';
            $parameters = $settings->get("quality.rollout.template.{$template}", []);
            if (! is_array($parameters) || $parameters === []) {
                throw new BusinessRuleException("Demo quality-plan seeding requires a {$template} parameter template.");
            }

            app(ItemQualityPlanService::class)->createRevision($item, [
                'sampling_method' => 'fixed',
                'fixed_sample_size' => $settings->requiredInt('quality.rollout.fixed_sample_size', 1, 1000),
                'parameters' => $parameters,
                'effective_from' => now()->toDateString(),
                'notes' => 'Baseline demo plan. QC must review and publish a tailored revision where drawing, COA, or supplier controls require tighter limits.',
            ], $actor);
        }

        $this->command?->info('Seeded '.count($items).' demo incoming quality plans.');
    }
}
