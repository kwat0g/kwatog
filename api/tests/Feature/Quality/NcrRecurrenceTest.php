<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NcrRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
        ]);
    }

    private function makeNcr(int $productId, string $description, ?\Carbon\Carbon $createdAt = null): NonConformanceReport
    {
        return app(NcrService::class)->create([
            'source'             => 'inspection_fail',
            'severity'           => 'medium',
            'product_id'         => $productId,
            'defect_description' => $description,
            'affected_quantity'  => 1,
            'is_auto_generated'  => false,
        ], $this->user())->fresh();
    }

    public function test_first_ncr_has_no_recurrence_link(): void
    {
        $product = Product::factory()->create();
        $ncr = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        $this->assertNull($ncr->recurrence_of_ncr_id);
    }

    public function test_second_ncr_same_product_same_signature_links_to_first(): void
    {
        $product = Product::factory()->create();
        $first  = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        $second = $this->makeNcr($product->id, 'Outer diameter out of tolerance');

        $this->assertSame((int) $first->id, (int) $second->fresh()->recurrence_of_ncr_id);
    }

    public function test_different_product_does_not_link(): void
    {
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $first  = $this->makeNcr($a->id, 'Outer diameter out of tolerance');
        $second = $this->makeNcr($b->id, 'Outer diameter out of tolerance');

        $this->assertNull($second->fresh()->recurrence_of_ncr_id);
    }

    public function test_outside_30_day_window_does_not_link(): void
    {
        $product = Product::factory()->create();
        $first  = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        // Backdate the first by 31 days so the window scan misses it.
        NonConformanceReport::query()->whereKey($first->id)
            ->update(['created_at' => now()->subDays(31)]);

        $second = $this->makeNcr($product->id, 'Outer diameter out of tolerance');
        $this->assertNull($second->fresh()->recurrence_of_ncr_id);
    }
}
