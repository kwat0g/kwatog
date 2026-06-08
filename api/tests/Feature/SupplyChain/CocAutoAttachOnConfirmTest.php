<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M-20 — Auto-attach CoC PDF as a DeliveryProof when a delivery is confirmed.
 *
 * Verifies one CoC row per passed outgoing inspection linked to the delivery,
 * idempotency on repeat confirms, that ineligible inspections are skipped,
 * and that deliveries with no inspection link still confirm cleanly.
 */
class CocAutoAttachOnConfirmTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(SettingsSeeder::class);
        Storage::fake('local');
        $this->svc = app(DeliveryService::class);
    }

    public function test_happy_path_attaches_one_coc_per_passed_outgoing_inspection(): void
    {
        $user = $this->makeUser();
        [$delivery] = $this->seedDeliveryWithInspection(
            $user,
            stage: InspectionStage::Outgoing,
            status: InspectionStatus::Passed,
        );

        $this->addProof($delivery, $user);

        $confirmed = $this->svc->confirm($delivery, $user);
        $this->assertSame('confirmed', $confirmed->status->value);

        $cocs = DeliveryProof::query()
            ->where('delivery_id', $delivery->id)
            ->where('proof_type', 'coc')
            ->get();

        $this->assertCount(1, $cocs, 'Exactly one CoC must be attached.');
        $coc = $cocs->first();
        $this->assertSame('application/pdf', $coc->mime_type);
        $this->assertSame($user->id, (int) $coc->uploaded_by);
        $this->assertNotNull($coc->file_size);
        $this->assertGreaterThan(0, (int) $coc->file_size);
        $this->assertTrue(Storage::disk('local')->exists($coc->file_path),
            "Stored CoC PDF must exist on local disk at {$coc->file_path}.");
        $this->assertStringStartsWith('CoC-', $coc->file_name);
    }

    public function test_idempotent_on_repeated_attach(): void
    {
        $user = $this->makeUser();
        [$delivery] = $this->seedDeliveryWithInspection(
            $user,
            stage: InspectionStage::Outgoing,
            status: InspectionStatus::Passed,
        );

        $this->addProof($delivery, $user);

        $this->svc->confirm($delivery, $user);
        // Call the private attach helper again via reflection to simulate
        // a second invocation path landing on the same delivery.
        $ref = new \ReflectionClass($this->svc);
        $method = $ref->getMethod('attachCertificatesOfConformance');
        $method->setAccessible(true);
        $method->invoke($this->svc, $delivery->fresh(), $user);

        $count = DeliveryProof::query()
            ->where('delivery_id', $delivery->id)
            ->where('proof_type', 'coc')
            ->count();
        $this->assertSame(1, $count, 'Re-running attach must not duplicate the CoC row.');
    }

    public function test_skips_ineligible_inspection(): void
    {
        $user = $this->makeUser();
        // Failed outgoing inspection — defensive pre-filter must skip it.
        [$delivery] = $this->seedDeliveryWithInspection(
            $user,
            stage: InspectionStage::Outgoing,
            status: InspectionStatus::Failed,
        );

        $this->addProof($delivery, $user);

        $confirmed = $this->svc->confirm($delivery, $user);
        $this->assertSame('confirmed', $confirmed->status->value);

        $cocCount = DeliveryProof::query()
            ->where('delivery_id', $delivery->id)
            ->where('proof_type', 'coc')
            ->count();
        $this->assertSame(0, $cocCount, 'Failed inspection must not produce a CoC.');
    }

    public function test_no_inspection_link_still_confirms_without_coc(): void
    {
        $user = $this->makeUser();
        [$delivery] = $this->seedDeliveryWithInspection(
            $user,
            stage: null,
            status: null,
        );

        $this->addProof($delivery, $user);

        $confirmed = $this->svc->confirm($delivery, $user);
        $this->assertSame('confirmed', $confirmed->status->value);

        $cocCount = DeliveryProof::query()
            ->where('delivery_id', $delivery->id)
            ->where('proof_type', 'coc')
            ->count();
        $this->assertSame(0, $cocCount, 'Without a linked inspection, no CoC should be created.');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        $role = Role::create([
            'name'        => 'CoC Auto Attach Test ' . uniqid(),
            'slug'        => 'coc_attach_test_' . uniqid(),
            'description' => 'Test',
        ]);
        $perm = Permission::firstOrCreate(
            ['slug' => 'supply_chain.deliveries.confirm'],
            ['name' => 'Confirm Delivery', 'module' => 'supply_chain'],
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    private function addProof(Delivery $d, User $by): void
    {
        DeliveryProof::create([
            'delivery_id' => $d->id,
            'proof_type'  => 'photo',
            'file_name'   => 'receipt.jpg',
            'file_path'   => "deliveries/{$d->id}/receipt.jpg",
            'mime_type'   => 'image/jpeg',
            'uploaded_by' => $by->id,
        ]);
    }

    /**
     * @return array{0: Delivery, 1: DeliveryItem, 2: ?Inspection}
     */
    private function seedDeliveryWithInspection(
        User $user,
        ?InspectionStage $stage,
        ?InspectionStatus $status,
    ): array {
        $customer = Customer::create([
            'name'               => 'Test Customer ' . uniqid(),
            'is_active'          => true,
            'payment_terms_days' => 30,
        ]);

        $product = Product::create([
            'part_number'     => strtoupper(substr(uniqid('PT-'), 0, 12)),
            'name'            => 'Wiper Bushing ' . uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost'   => '50.00',
            'is_active'       => true,
        ]);

        $so = SalesOrder::create([
            'so_number'    => 'SO-T-' . substr(uniqid(), -10),
            'customer_id'  => $customer->id,
            'date'         => now()->toDateString(),
            'subtotal'     => '0.00',
            'vat_amount'   => '0.00',
            'total_amount' => '0.00',
            'status'       => 'confirmed',
            'created_by'   => $user->id,
        ]);

        $soItem = SalesOrderItem::create([
            'sales_order_id'     => $so->id,
            'product_id'         => $product->id,
            'quantity'           => '5',
            'unit_price'         => '100.00',
            'total'              => '500.00',
            'quantity_delivered' => 0,
            'delivery_date'      => now()->addDays(7)->toDateString(),
        ]);

        $inspection = null;
        if ($stage !== null && $status !== null) {
            $inspection = Inspection::create([
                'inspection_number' => 'QC-TEST-' . substr(uniqid(), -8),
                'stage'             => $stage->value,
                'status'            => $status->value,
                'product_id'        => $product->id,
                'batch_quantity'    => 100,
                'sample_size'       => 8,
                'accept_count'      => $status === InspectionStatus::Passed ? 0 : 0,
                'reject_count'      => 0,
                'defect_count'      => 0,
                'inspector_id'      => $user->id,
                'completed_at'      => now(),
            ]);
        }

        $delivery = Delivery::create([
            'delivery_number' => 'DEL-TEST-' . uniqid(),
            'sales_order_id'  => $so->id,
            'status'          => 'delivered',
            'scheduled_date'  => now()->toDateString(),
            'delivered_at'    => now(),
            'created_by'      => $user->id,
        ]);

        $item = DeliveryItem::create([
            'delivery_id'         => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'inspection_id'       => $inspection?->id,
            'quantity'            => '5',
            'unit_price'          => '100.00',
        ]);

        return [$delivery, $item, $inspection];
    }
}
