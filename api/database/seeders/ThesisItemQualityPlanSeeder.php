<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Support\ProductionAssertions;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Models\ItemQualityPlan;
use App\Modules\Quality\Services\ItemQualityPlanService;
use Illuminate\Database\Seeder;

/** Adds baseline incoming QC plans to existing items that lack effective coverage. */
class ThesisItemQualityPlanSeeder extends Seeder
{
    public function run(): void
    {
        ProductionAssertions::assertDemoSeedingAllowed();

        $settings = app(SettingsService::class);
        $actorRoles = array_values(array_filter(
            (array) $settings->get('system.automation.actor_roles', []),
            static fn ($role): bool => is_string($role) && $role !== '',
        ));
        $actor = User::query()->where('is_active', true)
            ->whereHas('role', fn ($role) => $role->whereIn('slug', $actorRoles))
            ->orderBy('id')->first();
        if ($actor === null) {
            throw new \RuntimeException('Thesis QC plan seeding requires an active automation actor.');
        }

        $sampleSize = $settings->requiredInt('quality.rollout.fixed_sample_size', 1, 1000);
        $today = now()->toDateString();
        $plans = app(ItemQualityPlanService::class);
        $created = 0;
        $futurePlans = 0;

        foreach (Item::query()->orderBy('code')->get() as $item) {
            $hasEffectiveDefault = ItemQualityPlan::query()
                ->where('item_id', $item->id)
                ->whereNull('vendor_id')
                ->effective($today)
                ->exists();
            if ($hasEffectiveDefault) {
                continue;
            }

            // An already published future revision owns the next effective date.
            // Leave it intact instead of attempting to backdate another revision.
            $hasFutureDefault = ItemQualityPlan::query()
                ->where('item_id', $item->id)
                ->whereNull('vendor_id')
                ->where('is_active', true)
                ->whereDate('effective_from', '>', $today)
                ->exists();
            if ($hasFutureDefault) {
                $futurePlans++;
                continue;
            }

            $template = str_contains(strtolower($item->name.' '.$item->code), 'resin') ? 'resin' : 'general';
            $parameters = $settings->get("quality.rollout.template.{$template}", []);
            if (! is_array($parameters) || $parameters === []) {
                throw new \RuntimeException("Thesis QC plan seeding requires a {$template} parameter template.");
            }

            $plans->createRevision($item, [
                'sampling_method' => 'fixed',
                'fixed_sample_size' => $sampleSize,
                'parameters' => $parameters,
                'effective_from' => $today,
                'notes' => 'Fictional thesis-demo incoming QC baseline. QC should verify approved drawings, COAs, and item-specific limits before production use.',
            ], $actor);
            $created++;
        }

        $this->command?->info("Thesis incoming QC plans: {$created} created; {$futurePlans} future plans preserved.");
    }
}
