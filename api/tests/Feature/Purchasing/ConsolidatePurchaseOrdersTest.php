<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Events\PurchaseRequestApproved;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 2026-08-08 — Auto-PO on PR final approval.
 *
 * When a PR reaches `approved`, the ConsolidatePurchaseOrders listener must
 * convert it straight into PO(s) — grouping lines by their suggested vendor —
 * so nobody has to re-type the lines. A PR is only auto-converted when EVERY
 * line has a suggested vendor AND a unit price; otherwise the whole PR is left
 * approved for the manual convert-to-PO flow.
 */
class ConsolidatePurchaseOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function makePr(array $lines): PurchaseRequest
    {
        $requester = User::factory()->create();
        $pr = PurchaseRequest::factory()->create([
            'requested_by'   => $requester->id,
            'department_id'  => null,
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        foreach ($lines as $line) {
            PurchaseRequestItem::create(array_merge([
                'purchase_request_id' => $pr->id,
                'quantity'            => '10',
                'unit'                => 'pcs',
                'description'         => 'Auto-PO line',
            ], $line));
        }

        return $pr;
    }

    private function vendor(): Vendor
    {
        return Vendor::factory()->create();
    }

    public function test_approved_pr_is_auto_converted_into_a_draft_po(): void
    {
        $vendorA = $this->vendor();
        $item = Item::factory()->create();
        $pr = $this->makePr([[
            'item_id'              => $item->id,
            'suggested_vendor_id'  => $vendorA->id,
            'estimated_unit_price' => '250.00',
        ]]);

        event(new PurchaseRequestApproved($pr));

        $po = PurchaseOrder::where('purchase_request_id', $pr->id)->first();
        $this->assertNotNull($po, 'Auto-PO should be created from the approved PR.');
        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertTrue((bool) $po->is_auto_generated, 'Listener-created POs must be flagged is_auto_generated.');
        $this->assertSame($vendorA->id, $po->vendor_id);
        $this->assertSame(1, $po->items()->count());
        $this->assertSame('250.00', (string) $po->items()->first()->unit_price);
        $this->assertSame('10.00', (string) $po->items()->first()->quantity);

        // PR flips to converted exactly like the manual flow.
        $this->assertSame(PurchaseRequestStatus::Converted, $pr->fresh()->status);
    }

    public function test_lines_are_grouped_by_vendor_into_separate_pos(): void
    {
        $vendorA = $this->vendor();
        $vendorB = $this->vendor();
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();
        $pr = $this->makePr([
            [
                'item_id'              => $itemA->id,
                'suggested_vendor_id'  => $vendorA->id,
                'estimated_unit_price' => '100.00',
            ],
            [
                'item_id'              => $itemB->id,
                'suggested_vendor_id'  => $vendorB->id,
                'estimated_unit_price' => '200.00',
            ],
        ]);

        event(new PurchaseRequestApproved($pr));

        $pos = PurchaseOrder::where('purchase_request_id', $pr->id)->orderBy('id')->get();
        $this->assertCount(2, $pos);
        $this->assertSame([$vendorA->id, $vendorB->id], $pos->pluck('vendor_id')->all());
    }

    public function test_pr_with_a_line_missing_suggested_vendor_is_skipped_whole(): void
    {
        $vendorA = $this->vendor();
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();
        $pr = $this->makePr([
            [
                'item_id'              => $itemA->id,
                'suggested_vendor_id'  => $vendorA->id,
                'estimated_unit_price' => '100.00',
            ],
            [
                'item_id'              => $itemB->id,
                'suggested_vendor_id'  => null,
                'estimated_unit_price' => '200.00',
            ],
        ]);

        event(new PurchaseRequestApproved($pr));

        $this->assertSame(0, PurchaseOrder::where('purchase_request_id', $pr->id)->count());
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
    }

    public function test_pr_with_a_line_missing_unit_price_is_skipped_whole(): void
    {
        $vendorA = $this->vendor();
        $item = Item::factory()->create();
        $pr = $this->makePr([[
            'item_id'             => $item->id,
            'suggested_vendor_id' => $vendorA->id,
            'estimated_unit_price' => null,
        ]]);

        event(new PurchaseRequestApproved($pr));

        $this->assertSame(0, PurchaseOrder::where('purchase_request_id', $pr->id)->count());
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
    }

    public function test_dispatching_the_event_twice_never_double_creates(): void
    {
        $vendorA = $this->vendor();
        $item = Item::factory()->create();
        $pr = $this->makePr([[
            'item_id'              => $item->id,
            'suggested_vendor_id'  => $vendorA->id,
            'estimated_unit_price' => '250.00',
        ]]);

        event(new PurchaseRequestApproved($pr));
        // A stale queued copy arriving after conversion must be a no-op.
        event(new PurchaseRequestApproved($pr->fresh()));

        $this->assertSame(1, PurchaseOrder::where('purchase_request_id', $pr->id)->count());
    }

    public function test_pr_that_already_has_a_po_is_not_reconverted(): void
    {
        $vendorA = $this->vendor();
        $item = Item::factory()->create();
        $pr = $this->makePr([[
            'item_id'              => $item->id,
            'suggested_vendor_id'  => $vendorA->id,
            'estimated_unit_price' => '250.00',
        ]]);
        // Simulate a manual conversion that already happened while an event
        // copy was still queued: the PR now has a PO but is still marked
        // approved (e.g. legacy data), so only the exists() guard can stop it.
        PurchaseOrderItem::create([
            'purchase_order_id' => PurchaseOrder::create([
                'po_number'           => 'PO-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
                'vendor_id'           => $vendorA->id,
                'purchase_request_id' => $pr->id,
                'date'                => now()->toDateString(),
                'subtotal'            => '2500.00',
                'vat_amount'          => '0.00',
                'total_amount'        => '2500.00',
                'is_vatable'          => false,
            ])->id,
            'item_id'     => $item->id,
            'description' => 'Already converted',
            'quantity'    => '10.00',
            'unit'        => 'pcs',
            'unit_price'  => '250.00',
            'total'       => '2500.00',
        ]);

        event(new PurchaseRequestApproved($pr));

        $this->assertSame(1, PurchaseOrder::where('purchase_request_id', $pr->id)->count());
    }

    public function test_system_actor_is_used_when_the_requester_is_gone(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        $vendorA = $this->vendor();
        $item = Item::factory()->create();
        // No requester: the requester user no longer exists.
        $requester = User::factory()->create();
        $pr = PurchaseRequest::factory()->create([
            'requested_by'  => $requester->id,
            'department_id' => null,
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();
        // Soft-delete the requester (UPDATE, not DELETE — FK-safe) so the
        // listener's requester relation resolves to null and the automation
        // actor fallback kicks in.
        $requester->delete();
        PurchaseRequestItem::create([
            'purchase_request_id'  => $pr->id,
            'item_id'              => $item->id,
            'suggested_vendor_id'  => $vendorA->id,
            'estimated_unit_price' => '250.00',
            'quantity'             => '10',
            'unit'                 => 'pcs',
            'description'          => 'Auto-PO line',
        ]);

        event(new PurchaseRequestApproved($pr));

        $po = PurchaseOrder::where('purchase_request_id', $pr->id)->first();
        $this->assertNotNull($po);
        $this->assertSame($admin->id, $po->created_by);
    }

    public function test_final_approval_via_the_service_dispatches_the_auto_conversion(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $requester = User::factory()->create([
            'role_id' => Role::where('slug', 'department_head')->value('id'),
        ]);
        // One user per PR workflow step: dept head → manager → purchasing → VP.
        $approvers = [
            'department_head'     => User::factory()->create(['role_id' => Role::where('slug', 'department_head')->value('id')]),
            'production_manager'  => User::factory()->create(['role_id' => Role::where('slug', 'production_manager')->value('id')]),
            'purchasing_officer'  => User::factory()->create(['role_id' => Role::where('slug', 'purchasing_officer')->value('id')]),
            'system_admin'        => User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]),
        ];
        $vendorA = $this->vendor();
        $item = Item::factory()->create();

        $pr = PurchaseRequest::factory()->create([
            'requested_by'  => $requester->id,
            'department_id' => null,
        ]);
        // Factory default is draft — submit() requires draft, then flips to pending.
        PurchaseRequestItem::create([
            'purchase_request_id'  => $pr->id,
            'item_id'              => $item->id,
            'suggested_vendor_id'  => $vendorA->id,
            'estimated_unit_price' => '250.00',
            'quantity'             => '10',
            'unit'                 => 'pcs',
            'description'          => 'Auto-PO line',
        ]);
        $pr->load('items');

        $svc = app(PurchaseRequestService::class);
        $svc->submit($pr);
        // Walk the workflow in step order; the last approval flips the PR to
        // approved and fires the event (via afterCommit), which the listener
        // turns into an auto-PO.
        $pending = $pr->fresh();
        foreach ($approvers as $role => $user) {
            if ($pending->status !== PurchaseRequestStatus::Pending) {
                break;
            }
            $pending = $svc->approve($pending->fresh(), $user, "approve as {$role}");
        }

        $this->assertSame(PurchaseRequestStatus::Approved, $pending->status);
        $po = PurchaseOrder::where('purchase_request_id', $pr->id)->first();
        $this->assertNotNull($po, 'Approval through the real service should trigger the auto-PO.');
        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
    }

    public function test_listener_is_bound_to_the_event(): void
    {
        $this->assertTrue(Event::hasListeners(PurchaseRequestApproved::class));
    }

    public function test_skipped_pr_notifies_the_purchasing_audience(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $officer = User::factory()->create([
            'role_id' => Role::where('slug', 'purchasing_officer')->value('id'),
            'is_active' => true,
        ]);
        app(SettingsService::class)->set(
            'purchasing.purchase_request_approved.notification_roles',
            ['purchasing_officer']
        );

        $item = Item::factory()->create();
        // No suggested vendor → the whole PR is skipped for auto-conversion.
        $pr = $this->makePr([[
            'item_id'              => $item->id,
            'suggested_vendor_id'  => null,
            'estimated_unit_price' => '200.00',
        ]]);

        event(new PurchaseRequestApproved($pr));

        $this->assertSame(0, PurchaseOrder::where('purchase_request_id', $pr->id)->count());
        $row = DB::table('notifications')
            ->where('type', 'chain.pr_auto_convert_skipped')
            ->where('notifiable_id', $officer->id)
            ->first();
        $this->assertNotNull($row, 'Purchasing must be notified when auto-conversion is skipped.');
        $data = json_decode((string) $row->data, true);
        $this->assertStringContainsString($pr->pr_number, (string) ($data['title'] ?? ''));
        $this->assertStringContainsString('/purchasing/purchase-requests/', (string) ($data['link_to'] ?? ''));
    }
}
