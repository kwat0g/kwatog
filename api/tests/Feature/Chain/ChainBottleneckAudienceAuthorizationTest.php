<?php

declare(strict_types=1);

namespace Tests\Feature\Chain;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChainBottleneckAudienceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_actor_sees_only_own_bottleneck_audience_even_when_query_requests_another(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        app(SettingsService::class)->set('dashboard.chain_bottlenecks', [
            'invoice_draft_overdue' => ['label' => 'Finance invoices', 'hours' => 24, 'audience' => 'finance_officer'],
            'wo_confirmed_unstarted' => ['label' => 'Production work orders', 'hours' => 24, 'audience' => 'production_manager'],
            'pr_pending_overdue' => ['label' => 'PR awaiting next approver', 'hours' => 24, 'audience' => 'next_approver'],
        ]);
        $invoice = Invoice::factory()->create(['status' => 'draft', 'updated_at' => now()->subDays(2)]);
        $workOrder = WorkOrder::factory()->create(['status' => 'confirmed', 'updated_at' => now()->subDays(2)]);
        $requester = User::factory()->withRole('purchasing_officer')->create(['is_active' => true]);
        $purchaseRequest = PurchaseRequest::factory()->create(['requested_by' => $requester->id, 'department_id' => null]);
        $purchaseRequest = app(PurchaseRequestService::class)->submit($purchaseRequest, $requester);
        $purchaseRequest->forceFill(['updated_at' => now()->subDays(2)])->save();
        $finance = User::factory()->withRole('finance_officer')->create(['is_active' => true]);
        $productionManager = User::factory()->withRole('production_manager')->create(['is_active' => true]);

        $financeGroups = $this->activeGroups($this->actingAs($finance)->getJson('/api/v1/chain/bottlenecks')->assertOk()->json('data.groups'));
        $this->assertSame(['invoice_draft_overdue', 'pr_pending_overdue'], array_keys($financeGroups));
        $this->assertSame($invoice->invoice_number, $financeGroups['invoice_draft_overdue']['rows'][0]['doc_number']);
        $this->assertSame($purchaseRequest->pr_number, $financeGroups['pr_pending_overdue']['rows'][0]['doc_number']);
        $this->assertSame('finance_officer', $financeGroups['pr_pending_overdue']['rows'][0]['audience']);

        $financeNarrowed = $this->activeGroups($this->getJson('/api/v1/chain/bottlenecks?audience=finance_officer')->assertOk()->json('data.groups'));
        $this->assertSame(array_keys($financeGroups), array_keys($financeNarrowed));

        $financeWithForgedAudience = $this->activeGroups($this->getJson('/api/v1/chain/bottlenecks?audience=production_manager')->assertOk()->json('data.groups'));
        $this->assertSame([], $financeWithForgedAudience);

        $productionGroups = $this->activeGroups($this->actingAs($productionManager)->getJson('/api/v1/chain/bottlenecks')->assertOk()->json('data.groups'));
        $this->assertSame(['wo_confirmed_unstarted'], array_keys($productionGroups));
        $this->assertSame($workOrder->wo_number, $productionGroups['wo_confirmed_unstarted']['rows'][0]['doc_number']);

        $productionNarrowed = $this->activeGroups($this->getJson('/api/v1/chain/bottlenecks?audience=production_manager')->assertOk()->json('data.groups'));
        $this->assertSame(array_keys($productionGroups), array_keys($productionNarrowed));

        $productionWithForgedAudience = $this->activeGroups($this->getJson('/api/v1/chain/bottlenecks?audience=finance_officer')->assertOk()->json('data.groups'));
        $this->assertSame([], $productionWithForgedAudience);
    }

    /** @param array<int, array<string, mixed>> $groups
     * @return array<string, array<string, mixed>>
     */
    private function activeGroups(array $groups): array
    {
        return collect($groups)->filter(fn (array $group): bool => $group['count'] > 0)->keyBy('key')->all();
    }
}
