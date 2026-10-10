<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PricingReviewService;
use App\Modules\MRP\Events\BomPricingReviewRequested;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingReviewNotificationAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_lookups_do_not_repeat_for_each_product_notice(): void
    {
        $this->seed(RolePermissionSeeder::class);
        foreach ([
            'system_admin',
            'ppc_head',
            'finance_officer',
            'sales_officer',
            'warehouse_staff',
            'customer_service_officer',
        ] as $slug) {
            User::factory()->withRole($slug)->create();
        }

        $products = Product::factory()->count(4)->create([
            'base_selling_price' => null,
            'minimum_selling_price' => null,
        ]);
        $nonAdminUsers = User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($query) => $query->where('slug', '<>', 'system_admin'))
            ->count();
        $overrideQueries = 0;

        DB::listen(static function (QueryExecuted $query) use (&$overrideQueries): void {
            if (str_contains($query->sql, 'user_permission_overrides')) {
                $overrideQueries++;
            }
        });

        app(PricingReviewService::class)->handle(new BomPricingReviewRequested(
            $products->modelKeys(),
            'scheduled_review',
        ));

        $this->assertLessThanOrEqual(
            $nonAdminUsers,
            $overrideQueries,
            'A review batch should resolve each active user permission set once, not once per product notice.',
        );
        $this->assertGreaterThanOrEqual(
            4,
            DB::table('notifications')->where('type', 'pricing.review_required')->count(),
            'Each product risk should still create its real in-app notification.',
        );
    }
}
