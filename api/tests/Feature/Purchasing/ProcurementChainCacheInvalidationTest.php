<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Dashboard\Services\BadgeService;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Controllers\ProcurementChainController;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\ProcurementChainCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcurementChainCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase normally installs a test transaction manager tied
        // to its rollback wrapper. This test has no wrapper so it can commit;
        // attach a manager to the connection to exercise callbacks inside the
        // explicit transaction below.
        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
    }

    public static function tearDownAfterClass(): void
    {
        // This test must observe a real outer commit for after-commit observers.
        // Make the next RefreshDatabase test rebuild the test schema and remove
        // its committed fixtures.
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_each_p2p_source_change_invalidates_its_cached_summary_after_commit(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/v1/procurement/chain', 'GET');
        $request->setUserResolver(static fn (): User => $user);
        $controller = app(ProcurementChainController::class);
        $cache = app(ProcurementChainCache::class);

        foreach ([
            'procurement_chain_pr',
            'procurement_chain_po',
            'procurement_chain_grn',
            'procurement_chain_bills',
            'procurement_chain_three_way',
        ] as $key) {
            Cache::forget($cache->versionKey($key));
            Cache::forget($cache->initialSummaryKey($key));
        }
        Cache::forget('badges.version');

        $purchaseRequest = PurchaseRequest::factory()->create();
        $purchaseRequest->forceFill(['status' => 'pending'])->save();
        $purchaseOrder = PurchaseOrder::factory()->create();
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $purchaseOrder->vendor_id,
        ]);
        $bill = Bill::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $purchaseOrder->vendor_id,
            'date' => now()->toDateString(),
        ]);

        foreach ([
            'procurement_chain_pr',
            'procurement_chain_po',
            'procurement_chain_grn',
            'procurement_chain_bills',
            'procurement_chain_three_way',
        ] as $key) {
            Cache::forget($cache->versionKey($key));
            Cache::forget($cache->initialSummaryKey($key));
        }

        $before = $this->summary($controller, $request);
        $badgeVersionBeforeTransaction = BadgeService::version();
        self::assertSame(1, $before['material_requirements']['pr_pending']);
        self::assertSame(0, $before['material_requirements']['pr_approved']);
        self::assertSame(1, $before['material_requirements']['po_draft']);
        self::assertSame(0, $before['material_requirements']['po_sent']);
        self::assertSame(1, $before['receiving']['grn_pending_qc']);
        self::assertSame(0, $before['receiving']['grn_received']);
        self::assertSame(1, $before['billing']['bills_unpaid']);
        self::assertSame(1, $before['three_way_match']['matched']);

        DB::transaction(function () use ($controller, $purchaseRequest, $purchaseOrder, $grn, $bill, $request, $before, $badgeVersionBeforeTransaction): void {
            $purchaseRequest->forceFill(['status' => 'approved'])->save();
            $purchaseOrder->forceFill(['status' => 'approved'])->save();
            $grn->forceFill(['status' => 'accepted'])->save();
            $bill->forceFill(['status' => 'cancelled'])->save();

            // Cache generation metadata must advance only after commit too;
            // otherwise a concurrent badge read can memoize pre-commit rows
            // under the new version and keep them until the TTL expires.
            self::assertSame($badgeVersionBeforeTransaction, BadgeService::version());

            // The still-open transaction must not evict the cache: a concurrent
            // reader must not recompute against uncommitted state.
            self::assertSame($before, $this->summary($controller, $request));
        });

        self::assertNotSame($badgeVersionBeforeTransaction, BadgeService::version());

        $after = $this->summary($controller, $request);
        self::assertSame(0, $after['material_requirements']['pr_pending']);
        self::assertSame(1, $after['material_requirements']['pr_approved']);
        self::assertSame(0, $after['material_requirements']['po_draft']);
        self::assertSame(1, $after['material_requirements']['po_sent']);
        self::assertSame(0, $after['receiving']['grn_pending_qc']);
        self::assertSame(1, $after['receiving']['grn_received']);
        self::assertSame(0, $after['billing']['bills_unpaid']);
        self::assertSame('0', (string) $after['billing']['bills_this_month']);
        self::assertSame(0, $after['three_way_match']['matched']);
    }

    public function test_manual_badge_invalidation_waits_for_the_transaction_commit(): void
    {
        Cache::forget('badges.version');
        $versionBefore = BadgeService::version();

        DB::transaction(function () use ($versionBefore): void {
            BadgeService::touch();

            self::assertSame($versionBefore, BadgeService::version());
        });

        self::assertNotSame($versionBefore, BadgeService::version());
    }

    /** @return array<string, mixed> */
    private function summary(ProcurementChainController $controller, Request $request): array
    {
        return $controller->index($request)->getData(true)['data'];
    }
}
