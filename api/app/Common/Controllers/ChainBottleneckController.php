<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\ChainBottleneckService;
use App\Common\Support\ApprovalSourceScope;
use App\Common\Support\ApprovalTypeRegistry;
use App\Common\Support\ChainDefinitions;
use App\Common\Support\HashIdFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Series C — Task C5. Endpoint backing the dashboard bottleneck widget.
 *
 * GET /api/v1/chain/bottlenecks
 *   Returns only rows assigned to the requesting user's current role or an
 *   active delegated role. `audience` can narrow that scope, never widen it.
 */
class ChainBottleneckController
{
    public function __construct(private readonly ChainBottleneckService $service) {}

    public function index(Request $request): JsonResponse
    {
        $all = $this->service->detectAll();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $actor = $actor->fresh(['role']);
        abort_unless($actor instanceof User && $actor->is_active, 401, 'This account is inactive.');

        $audiences = array_values(array_unique(array_filter([
            $actor->role?->slug,
            ...ApprovalDelegation::actsForRoles($actor->id, now()),
        ], static fn ($slug): bool => is_string($slug) && $slug !== '')));
        $requestedAudience = $request->query('audience');
        if ($request->query->has('audience')) {
            $requestedAudience = is_string($requestedAudience) && trim($requestedAudience) !== ''
                ? trim($requestedAudience)
                : false;
            if ($requestedAudience === false || ! in_array($requestedAudience, $audiences, true)) {
                $audiences = [];
            } else {
                $audiences = [$requestedAudience];
            }
        } else {
            $requestedAudience = null;
        }

        $purchaseRequestIds = [];
        foreach ($all as $rows) {
            foreach ($rows as $row) {
                if (($row['audience'] ?? null) === 'next_approver'
                    && ($row['entity_type'] ?? null) === 'purchase_request') {
                    $id = HashIdFilter::decode($row['entity_id'] ?? null, PurchaseRequest::class);
                    if ($id !== null) {
                        $purchaseRequestIds[] = $id;
                    }
                }
            }
        }
        $purchaseRequestIds = array_values(array_unique($purchaseRequestIds));
        $pendingApproverByRequest = [];
        $visiblePurchaseRequestIds = [];
        if ($purchaseRequestIds !== []) {
            $pendingApproverByRequest = DB::table('approval_records')
                ->select(['approvable_id', 'role_slug', 'step_order', 'id'])
                ->where('approvable_type', (new PurchaseRequest)->getMorphClass())
                ->whereIn('approvable_id', $purchaseRequestIds)
                ->where('is_current', true)
                ->where('action', 'pending')
                ->orderBy('step_order')
                ->orderBy('id')
                ->get()
                ->groupBy('approvable_id')
                ->map(static fn ($records): string => (string) $records->first()->role_slug)
                ->all();

            $prMeta = ApprovalTypeRegistry::forClass(PurchaseRequest::class);
            if ($prMeta !== null && ApprovalTypeRegistry::userCanView($actor, $prMeta)) {
                $visiblePurchaseRequestIds = ApprovalSourceScope::visibleIds(PurchaseRequest::class, $purchaseRequestIds, $actor);
            }
        }

        foreach ($all as $key => $rows) {
            $visibleRows = [];
            foreach ($rows as $row) {
                $audience = $row['audience'] ?? null;
                if ($audience === 'next_approver') {
                    if (($row['entity_type'] ?? null) !== 'purchase_request') {
                        continue;
                    }
                    $id = HashIdFilter::decode($row['entity_id'] ?? null, PurchaseRequest::class);
                    if ($id === null || ! isset($visiblePurchaseRequestIds[$id])) {
                        continue;
                    }
                    $audience = $pendingApproverByRequest[$id] ?? null;
                    if ($audience === null) {
                        continue;
                    }
                    $row['audience'] = $audience;
                }

                if (! is_string($audience) || ! in_array($audience, $audiences, true)) {
                    continue;
                }
                if ($requestedAudience !== null && $audience !== $requestedAudience) {
                    continue;
                }
                if (! $actor->hasPermission(ChainDefinitions::viewPermission((string) ($row['entity_type'] ?? '')))) {
                    continue;
                }
                $visibleRows[] = $row;
            }
            $all[$key] = $visibleRows;
        }

        // Build summary counts per group for cheap rendering on the SPA.
        $groups = [];
        $totalCount = 0;
        foreach ($all as $key => $rows) {
            $count = count($rows);
            $totalCount += $count;
            $groups[] = [
                'key' => $key,
                'label' => $rows[0]['label'] ?? $key,
                'audience' => $rows[0]['audience'] ?? null,
                'count' => $count,
                'rows' => $rows,
            ];
        }

        return response()->json([
            'data' => [
                'total' => $totalCount,
                'groups' => $groups,
                'automation' => $this->service->automationSummary(),
            ],
        ]);
    }
}
