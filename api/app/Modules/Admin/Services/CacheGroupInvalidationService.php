<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Common\Events\CacheGroupInvalidated;
use App\Common\Models\AuditLog;
use App\Common\Services\OutboxService;
use App\Modules\Accounting\Services\Statements\FinancialStatementCacheGeneration;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\BadgeService;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use App\Modules\Purchasing\Services\ProcurementChainCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Explicit, audited invalidation of the cache families with generation keys. */
final class CacheGroupInvalidationService
{
    /** @return array<string, array{label: string, description: string, families: list<string>}> */
    public function groups(): array
    {
        return [
            'dashboards' => [
                'label' => 'Generation-backed dashboard and widget snapshots',
                'description' => 'Retires the shared generation root used by registered dashboard, widget-summary, and badge caches. Direct-key caches are not included.',
                'families' => ['dashboard generation', 'widget-summary generations', 'badge generation'],
            ],
            'financial-statements' => [
                'label' => 'Financial statement snapshots',
                'description' => 'Retires versioned balance sheet, income statement, and cash flow snapshots.',
                'families' => ['financial statement snapshots'],
            ],
            'procurement-chain' => [
                'label' => 'Procurement chain summaries',
                'description' => 'Retires purchase request, purchase order, goods receipt, bills, and three-way match summaries.',
                'families' => [
                    'procurement_chain_pr',
                    'procurement_chain_po',
                    'procurement_chain_grn',
                    'procurement_chain_bills',
                    'procurement_chain_three_way',
                ],
            ],
        ];
    }

    /** @return array{group: string, families: list<string>, invalidation: 'generation_rotated'|'generation_rotation_failed'|'generation_rotation_requested'} */
    public function clear(string $group, User $actor): array
    {
        $definition = $this->groups()[$group] ?? null;
        abort_if($definition === null, 404);

        $request = request();
        $invalidationId = (string) Str::uuid();
        $rotationOutcome = null;
        DB::transaction(function () use ($group, $definition, $actor, $request, $invalidationId, &$rotationOutcome): void {
            AuditLog::query()->create([
                'user_id' => $actor->getKey(),
                'actor_type' => 'user',
                'action' => 'cache_group_invalidation_requested',
                'model_type' => 'cache_group',
                'model_id' => null,
                'old_values' => null,
                'new_values' => [
                    'group' => $group,
                    'families' => $definition['families'],
                    'strategy' => 'generation_rotation',
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source_command' => (string) ($request->route()?->getName() ?? 'admin.cache-groups.clear'),
                'correlation_id' => $request->attributes->get('request_id') ?? $request->header('X-Request-ID'),
                'reason' => 'Administrative cache group invalidation',
                'created_at' => now(),
            ]);

            // Register generation retirement before the outbox callback below,
            // so subscribers can only refetch after the new cache generation is active.
            DB::afterCommit(function () use ($group, &$rotationOutcome): void {
                try {
                    $rotated = match ($group) {
                        'dashboards' => $this->rotateDashboardGenerations(),
                        'financial-statements' => FinancialStatementCacheGeneration::rotateAfterCommit() === true,
                        'procurement-chain' => app(ProcurementChainCache::class)->invalidateAll(),
                        default => false,
                    };
                    $rotationOutcome = $rotated ? 'generation_rotated' : 'generation_rotation_failed';
                    if (! $rotated) {
                        Log::warning('Administrative cache group generation rotation did not complete.', [
                            'group' => $group,
                        ]);
                    }
                } catch (Throwable $exception) {
                    $rotationOutcome = 'generation_rotation_failed';
                    Log::warning('Administrative cache group invalidation failed after commit.', [
                        'group' => $group,
                        'exception' => $exception::class,
                    ]);
                }

            });

            // Persist with the audit row; OutboxService registers publication
            // after this transaction's earlier cache-rotation callback.
            app(OutboxService::class)->record(
                new CacheGroupInvalidated($group, $invalidationId),
                dedupeKey: 'cache-group-invalidation:'.$invalidationId,
                publishInlineAfterCommit: true,
            );
        });

        return [
            'group' => $group,
            'families' => $definition['families'],
            'invalidation' => $rotationOutcome ?? 'generation_rotation_requested',
        ];
    }

    private function rotateDashboardGenerations(): bool
    {
        $dashboardRotated = DashboardCacheGeneration::touch() === true;
        $badgeRotated = BadgeService::rotateVersionAfterCommit() === true;

        return $dashboardRotated && $badgeRotated;
    }
}
