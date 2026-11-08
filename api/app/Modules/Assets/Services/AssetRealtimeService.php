<?php

declare(strict_types=1);

namespace App\Modules\Assets\Services;

use App\Common\Services\OutboxService;
use App\Modules\Assets\Events\AssetChanged;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Stages durable, coalesced refresh signals for asset mutations. */
final class AssetRealtimeService
{
    private const MAX_ASSETS_PER_EVENT = 100;

    public function stage(Asset $asset, string $action, ?User $actor = null): void
    {
        $this->stageMany([$asset], $action, $actor);
    }

    /**
     * One depreciation run can touch many assets. Store and broadcast one
     * refresh event addressed to those private record channels plus the list
     * channel, rather than enqueueing one job per row.
     *
     * @param  list<Asset>  $assets
     */
    public function stageMany(array $assets, string $action, ?User $actor = null): void
    {
        $assetsById = [];
        foreach ($assets as $asset) {
            $assetsById[(int) $asset->getKey()] = $asset;
        }
        if ($assetsById === []) {
            return;
        }

        ksort($assetsById);
        $chunks = array_chunk(array_values($assetsById), self::MAX_ASSETS_PER_EVENT);
        $transactionId = DB::transactionLevel() > 0 && DB::connection()->getDriverName() === 'pgsql'
            ? (string) DB::scalar('select txid_current()')
            : (string) Str::uuid();
        $actor ??= auth()->user();

        foreach ($chunks as $chunk) {
            $hashIds = array_map(static fn (Asset $asset): string => (string) $asset->hash_id, $chunk);
            $version = collect($chunk)
                ->map(static fn (Asset $asset): ?string => $asset->updated_at?->format('Y-m-d\TH:i:s.uP'))
                ->filter()
                ->max() ?? now()->format('Y-m-d\TH:i:s.uP');
            $scope = count($hashIds) === 1
                ? $hashIds[0]
                : 'batch:'.hash('sha256', implode(',', $hashIds));

            app(OutboxService::class)->recordCoalesced(
                new AssetChanged(
                    assetHashIds: $hashIds,
                    action: $action,
                    version: $version,
                    actorHashId: $actor instanceof User ? (string) $actor->hash_id : null,
                ),
                "asset-refresh:{$scope}:{$transactionId}",
            );
        }
    }
}
