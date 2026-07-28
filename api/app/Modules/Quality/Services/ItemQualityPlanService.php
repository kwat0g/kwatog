<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Quality\Models\ItemQualityPlan;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ItemQualityPlanService
{
    /** @return Collection<int, ItemQualityPlan> */
    public function revisions(Item $item): Collection
    {
        return ItemQualityPlan::query()
            ->with(['vendor:id,name', 'creator:id,name'])
            ->where('item_id', $item->id)
            ->orderByRaw('vendor_id NULLS FIRST')
            ->orderByDesc('version')
            ->get();
    }

    public function createRevision(Item $item, array $data, User $user): ItemQualityPlan
    {
        $vendorId = $this->vendorId($data['vendor_id'] ?? null);

        return DB::transaction(function () use ($item, $data, $user, $vendorId): ItemQualityPlan {
            $current = ItemQualityPlan::query()
                ->where('item_id', $item->id)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            if ($current?->is_active) {
                $current->update([
                    'is_active' => false,
                    'effective_to' => now()->subDay()->toDateString(),
                ]);
            }

            $plan = ItemQualityPlan::query()->create([
                'item_id' => $item->id,
                'vendor_id' => $vendorId,
                'version' => ($current?->version ?? 0) + 1,
                'stage' => 'incoming',
                'sampling_method' => $data['sampling_method'],
                'fixed_sample_size' => $data['sampling_method'] === 'fixed' ? $data['fixed_sample_size'] : null,
                'aql_level' => $data['sampling_method'] === 'aql' ? ($data['aql_level'] ?? 'general_ii') : null,
                'parameters' => array_values($data['parameters']),
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            return $plan->load(['item:id,code,name', 'vendor:id,name', 'creator:id,name']);
        });
    }

    public function deactivate(ItemQualityPlan $plan, User $user): ItemQualityPlan
    {
        $plan->update([
            'is_active' => false,
            'effective_to' => min(now()->toDateString(), $plan->effective_to?->toDateString() ?? now()->toDateString()),
        ]);

        return $plan->fresh()->load(['item:id,code,name', 'vendor:id,name', 'creator:id,name']);
    }

    public function activeFor(Item $item, ?int $vendorId, ?string $date = null): ?ItemQualityPlan
    {
        $base = ItemQualityPlan::query()->where('item_id', $item->id)->effective($date);

        if ($vendorId) {
            $specific = (clone $base)->where('vendor_id', $vendorId)->latest('version')->first();
            if ($specific) {
                return $specific;
            }
        }

        return $base->whereNull('vendor_id')->latest('version')->first();
    }

    private function vendorId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return Vendor::query()->findOrFail($value)->id;
        }
        $decoded = app('hashids')->decode((string) $value);

        return Vendor::query()->findOrFail($decoded[0] ?? 0)->id;
    }
}
