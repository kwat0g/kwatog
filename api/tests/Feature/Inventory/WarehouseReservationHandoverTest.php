<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrderMaterial;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseReservationHandoverTest extends TestCase
{
    use RefreshDatabase;

    private function warehouse(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
    }

    private function issue(): array
    {
        $wo = WorkOrder::factory()->create(['status' => 'confirmed']);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        WorkOrderMaterial::create(['work_order_id' => $wo->id, 'item_id' => $item->id, 'bom_quantity' => 3, 'standard_cost' => 3]);
        $recipient = Employee::factory()->create(['department_id' => Department::factory()->create(['code' => 'PROD', 'is_active' => true])->id]);
        $crew = WorkOrderCrew::create(['work_order_id' => $wo->id, 'recorder_employee_id' => $recipient->id, 'assigned_by' => User::factory()->create()->id, 'assigned_at' => now(), 'reason' => 'Fixture']);
        $crew->members()->create(['employee_id' => $recipient->id, 'employee_snapshot' => []]);
        $movement = StockMovement::create(['item_id' => $item->id, 'from_location_id' => $location->id,
            'movement_type' => 'material_issue', 'quantity' => 3, 'unit_cost' => 1, 'total_cost' => 3,
            'reference_type' => 'work_order', 'reference_id' => $wo->id, 'created_at' => now()]);
        StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 7, 'reserved_quantity' => 0]);

        return [$wo, $recipient, $movement];
    }

    public function test_handover_records_receipt_once_without_posting_stock_again(): void
    {
        $actor = $this->warehouse();
        [$wo, $recipient, $movement] = $this->issue();
        $url = '/api/v1/inventory/stock-movements/'.$movement->hash_id;
        $this->actingAs($actor)->getJson($url.'/handover-options')->assertOk()
            ->assertJsonPath('data.quantity', '3.000')->assertJsonPath('data.recipients.0.value', $recipient->hash_id);
        $data = ['received_by_employee_id' => $recipient->hash_id, 'expected_quantity' => '3.000'];
        $this->postJson($url.'/handover', $data)->assertOk()->assertJsonPath('data.recipient_name', $recipient->full_name);
        $this->postJson($url.'/handover', $data)->assertOk();
        $this->assertDatabaseCount('material_handovers', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_levels', ['item_id' => $movement->item_id, 'quantity' => 7]);
        $this->postJson($url.'/handover', [...$data, 'expected_quantity' => '2'])->assertConflict();
        $this->getJson('/api/v1/inventory/stock-movements?movement_id='.$movement->hash_id)->assertOk()
            ->assertJsonPath('data.0.handover.recipient_name', $recipient->full_name);
    }

    public function test_grouped_handover_replays_without_stock_and_rolls_back_stale_lines(): void
    {
        $actor = $this->warehouse();
        [$wo, $recipient, $first] = $this->issue();
        $second = $first->replicate();
        $second->save();
        $payload = ['received_by_employee_id' => $recipient->hash_id, 'sources' => [
            ['movement_id' => $first->hash_id, 'expected_quantity' => '3.000'],
            ['movement_id' => $second->hash_id, 'expected_quantity' => '2.000'],
        ]];
        $this->actingAs($actor)->postJson('/api/v1/inventory/material-handovers/group', $payload)->assertConflict();
        $this->assertDatabaseCount('material_handovers', 0);
        $payload['sources'][1]['expected_quantity'] = '3.000';
        $this->postJson('/api/v1/inventory/material-handovers/group', $payload)->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/v1/inventory/material-handovers/group', $payload)->assertOk();
        $this->assertDatabaseCount('material_handovers', 2);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_handover_queue_groups_auto_and_manual_sources_and_search_keeps_complete_detail(): void
    {
        $actor = $this->warehouse();
        [$wo, $recipient, $first] = $this->issue();
        $slip = MaterialIssueSlip::create(['slip_number' => 'MIS-QUEUE', 'work_order_id' => $wo->id,
            'issued_date' => now()->toDateString(), 'issued_by' => $actor->id, 'created_by' => $actor->id,
            'status' => 'issued', 'total_value' => 3]);
        $second = $first->replicate();
        $second->reference_type = 'material_issue_slip';
        $second->reference_id = $slip->id;
        $second->save();
        $slip->items()->create(['item_id' => $second->item_id, 'location_id' => $second->from_location_id,
            'stock_movement_id' => $second->id, 'quantity_issued' => 3, 'unit_cost' => 1, 'total_cost' => 3]);
        $this->actingAs($actor)->getJson('/api/v1/inventory/material-handovers?per_page=1&search='.$wo->wo_number)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.pending_lines', 2)
            ->assertJsonPath('data.0.work_order_id', $wo->hash_id);
        $this->getJson('/api/v1/inventory/material-handovers/sources?work_order_id='.$wo->hash_id)
            ->assertOk()->assertJsonCount(2, 'data.sources')
            ->assertJsonPath('data.default_recipient_id', $recipient->hash_id)
            ->assertJsonPath('data.sources.0.remaining_quantity', '3.000');
        $this->getJson('/api/v1/inventory/material-handovers/sources?material_issue_id='.$slip->hash_id)
            ->assertOk()->assertJsonPath('data.work_order_id', $wo->hash_id)->assertJsonCount(2, 'data.sources');
        $this->assertDatabaseCount('material_handovers', 0);
    }

    public function test_queue_returns_and_terminal_records_have_truthful_non_actionable_states(): void
    {
        $actor = $this->warehouse();
        [$wo, , $issue] = $this->issue();
        $this->actingAs($actor);
        $wo->update(['status' => 'closed']);
        $this->getJson('/api/v1/inventory/material-handovers?status=needs_review')
            ->assertOk()->assertJsonPath('data.0.status', 'needs_review');
        $this->getJson('/api/v1/inventory/material-handovers?status=pending')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/material-handovers?status=')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/material-handovers/sources?work_order_id='.$wo->hash_id)
            ->assertOk()->assertJsonPath('data.sources.0.eligible', false);
        StockMovement::create(['item_id' => $issue->item_id, 'to_location_id' => $issue->from_location_id,
            'movement_type' => 'material_return', 'quantity' => 3, 'unit_cost' => 1, 'total_cost' => 3,
            'reference_type' => 'stock_movement', 'reference_id' => $issue->id, 'created_at' => now()]);
        $this->getJson('/api/v1/inventory/material-handovers/sources?work_order_id='.$wo->hash_id)
            ->assertOk()->assertJsonPath('data.pending_lines', 0)
            ->assertJsonPath('data.sources.0.remaining_quantity', '0.000')
            ->assertJsonPath('data.sources.0.eligible', false);
        $this->getJson('/api/v1/inventory/stock-movements?movement_id='.$issue->hash_id)
            ->assertOk()->assertJsonPath('data.0.handover_remaining_quantity', '0.000');
    }

    public function test_handover_sources_validate_context_and_authorize_reads(): void
    {
        $actor = $this->warehouse();
        [$wo] = $this->issue();
        $this->actingAs($actor)->getJson('/api/v1/inventory/material-handovers/sources')->assertUnprocessable();
        $this->getJson('/api/v1/inventory/material-handovers/sources?work_order_id=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/inventory/material-handovers/sources?work_order_id='.$wo->hash_id.'&material_issue_id=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/inventory/material-handovers?status=invalid')->assertUnprocessable();
        $employee = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id')]);
        $this->actingAs($employee)->getJson('/api/v1/inventory/material-handovers')->assertForbidden();
        $this->getJson('/api/v1/inventory/material-handovers/sources?work_order_id='.$wo->hash_id)->assertForbidden();
    }

    public function test_handover_details_keep_archived_material_history(): void
    {
        $warehouse = $this->warehouse();
        [$wo, , $source] = $this->issue();
        $item = $source->item;
        $item->delete();
        $this->actingAs($warehouse)->getJson('/api/v1/inventory/material-handovers/sources?work_order_id='.$wo->hash_id)
            ->assertOk()->assertJsonPath('data.sources.0.item.id', $item->hash_id);
    }

    public function test_queue_paginates_work_orders_and_material_search_does_not_hide_other_sources(): void
    {
        $actor = $this->warehouse();
        [$wo, , $first] = $this->issue();
        $otherItem = Item::factory()->create(['code' => 'HANDOVER-MATCH']);
        WorkOrderMaterial::create(['work_order_id' => $wo->id, 'item_id' => $otherItem->id, 'bom_quantity' => 1, 'standard_cost' => 1]);
        $second = $first->replicate();
        $second->item_id = $otherItem->id;
        $second->save();
        $otherWo = WorkOrder::factory()->create(['status' => 'in_progress']);
        WorkOrderMaterial::create(['work_order_id' => $otherWo->id, 'item_id' => $first->item_id, 'bom_quantity' => 3, 'standard_cost' => 3]);
        $third = $first->replicate();
        $third->reference_id = $otherWo->id;
        $third->save();
        $this->actingAs($actor)->getJson('/api/v1/inventory/material-handovers?per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/material-handovers?search=HANDOVER-MATCH')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_lines', 2);
        $this->getJson('/api/v1/inventory/material-handovers/sources?work_order_id='.$wo->hash_id)
            ->assertOk()->assertJsonCount(2, 'data.sources');
    }

    public function test_queue_keeps_unlinked_slips_and_group_confirmation_cannot_mix_work_orders(): void
    {
        $actor = $this->warehouse();
        [$wo, $recipient, $first] = $this->issue();
        $slip = MaterialIssueSlip::create(['slip_number' => 'MIS-UNLINKED-QUEUE', 'issued_date' => now()->toDateString(),
            'issued_by' => $actor->id, 'created_by' => $actor->id, 'status' => 'issued', 'total_value' => 3]);
        $second = $first->replicate();
        $second->reference_type = 'material_issue_slip';
        $second->reference_id = $slip->id;
        $second->save();
        $slip->items()->create(['item_id' => $second->item_id, 'location_id' => $second->from_location_id, 'stock_movement_id' => $second->id,
            'quantity_issued' => 3, 'unit_cost' => 1, 'total_cost' => 3]);
        $this->actingAs($actor)->getJson('/api/v1/inventory/material-handovers?search=MIS-UNLINKED-QUEUE')
            ->assertOk()->assertJsonPath('data.0.work_order_id', null)->assertJsonPath('data.0.material_issue_id', $slip->hash_id);
        $this->getJson('/api/v1/inventory/material-handovers/sources?material_issue_id='.$slip->hash_id)
            ->assertOk()->assertJsonPath('data.label', 'MIS-UNLINKED-QUEUE')->assertJsonCount(1, 'data.sources');
        $this->postJson('/api/v1/inventory/material-handovers/group', ['received_by_employee_id' => $recipient->hash_id,
            'sources' => [['movement_id' => $first->hash_id, 'expected_quantity' => '3.000'], ['movement_id' => $second->hash_id, 'expected_quantity' => '3.000']]])
            ->assertUnprocessable();
        $this->assertDatabaseCount('material_handovers', 0);
    }

    public function test_handover_rejects_unassigned_workers_returns_and_closed_orders(): void
    {
        $actor = $this->warehouse();
        [$wo, $recipient, $movement] = $this->issue();
        $other = Employee::factory()->create(['department_id' => $recipient->department_id]);
        $url = '/api/v1/inventory/stock-movements/'.$movement->hash_id.'/handover';
        $this->actingAs($actor)->postJson($url, ['received_by_employee_id' => $other->hash_id, 'expected_quantity' => '3'])->assertUnprocessable();
        StockMovement::create(['item_id' => $movement->item_id, 'to_location_id' => $movement->from_location_id,
            'movement_type' => 'material_return', 'quantity' => 1, 'unit_cost' => 1, 'total_cost' => 1,
            'reference_type' => 'stock_movement', 'reference_id' => $movement->id, 'created_at' => now()]);
        $this->postJson($url, ['received_by_employee_id' => $recipient->hash_id, 'expected_quantity' => '3'])->assertConflict();
        $wo->update(['status' => 'closed']);
        $this->postJson($url, ['received_by_employee_id' => $recipient->hash_id, 'expected_quantity' => '2'])->assertConflict();
        $this->assertDatabaseCount('material_handovers', 0);
    }

    public function test_reservations_filter_work_orders_without_exposing_other_allocations(): void
    {
        $actor = $this->warehouse();
        [$wo, , $movement] = $this->issue();
        $other = WorkOrder::factory()->create();
        foreach ([$wo, $other] as $record) {
            MaterialReservation::create(['item_id' => $movement->item_id,
                'location_id' => $movement->from_location_id, 'work_order_id' => $record->id, 'quantity' => 1, 'status' => 'reserved', 'reserved_at' => now()]);
        }
        $this->actingAs($actor)->getJson('/api/v1/inventory/material-reservations?work_order_id='.$wo->hash_id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_order.wo_number', $wo->wo_number);
        $this->getJson('/api/v1/inventory/material-reservations?search='.$wo->wo_number)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/material-reservations?work_order_id=invalid')->assertUnprocessable();
        $employee = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id')]);
        $this->actingAs($employee)->postJson('/api/v1/inventory/stock-movements/'.$movement->hash_id.'/handover',
            ['received_by_employee_id' => 'invalid', 'expected_quantity' => '1'])->assertForbidden();
    }

    public function test_grouped_reservations_paginate_whole_work_orders_and_preserve_line_filters(): void
    {
        $actor = $this->warehouse();
        $first = WorkOrder::factory()->create();
        $second = WorkOrder::factory()->create();
        $resin = Item::factory()->create(['code' => 'GROUP-RESIN', 'unit_of_measure' => 'kg']);
        $bags = Item::factory()->create(['code' => 'GROUP-BAG', 'unit_of_measure' => 'pcs']);
        $location = WarehouseLocation::factory()->create();
        foreach ([[$first, $resin, 'reserved'], [$second, $resin, 'reserved'],
            [$first, $resin, 'issued'], [$first, $bags, 'reserved']] as [$wo, $item, $status]) {
            MaterialReservation::create(['work_order_id' => $wo->id, 'item_id' => $item->id,
                'location_id' => $location->id, 'quantity' => 1, 'status' => $status, 'reserved_at' => now()]);
        }
        $url = '/api/v1/inventory/material-reservations?group_by=work_order&per_page=1';
        $this->actingAs($actor)->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->hash_id)
            ->assertJsonPath('data.0.work_order.wo_number', $first->wo_number)
            ->assertJsonCount(3, 'data.0.materials')
            ->assertJsonPath('data.0.materials.0.item.code', 'GROUP-BAG')
            ->assertJsonPath('data.0.materials.0.item.unit_of_measure', 'pcs')
            ->assertJsonPath('data.0.materials.1.status', 'issued');
        $this->getJson($url.'&page=2')->assertOk()
            ->assertJsonPath('data.0.id', $second->hash_id)->assertJsonCount(1, 'data.0.materials');
        $this->getJson($url.'&status=reserved&search=GROUP-BAG')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data.0.materials')
            ->assertJsonPath('data.0.materials.0.item.code', 'GROUP-BAG');
        $this->getJson($url.'&status=issued')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data.0.materials')
            ->assertJsonPath('data.0.materials.0.status', 'issued');
        $this->assertDatabaseCount('material_reservations', 4);
    }

    public function test_grouped_reservations_keep_unlinked_lines_and_validate_grouping(): void
    {
        $actor = $this->warehouse();
        MaterialReservation::create(['work_order_id' => null, 'item_id' => Item::factory()->create()->id,
            'quantity' => 1, 'status' => 'released', 'reserved_at' => now()]);
        $this->actingAs($actor)->getJson('/api/v1/inventory/material-reservations?group_by=work_order')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 'unlinked')
            ->assertJsonPath('data.0.work_order', null)->assertJsonCount(1, 'data.0.materials');
        $this->getJson('/api/v1/inventory/material-reservations?group_by=invalid')->assertUnprocessable();
    }

    public function test_cancelled_manual_slip_cannot_receive_a_handover(): void
    {
        $actor = $this->warehouse();
        [$wo, $recipient, $movement] = $this->issue();
        $slip = MaterialIssueSlip::create(['slip_number' => 'MIS-HANDOVER-TEST', 'work_order_id' => $wo->id,
            'issued_date' => now()->toDateString(), 'issued_by' => $actor->id, 'created_by' => $actor->id, 'status' => 'issued', 'total_value' => 3]);
        $movement->update(['reference_type' => 'material_issue_slip', 'reference_id' => $slip->id]);
        $slip->items()->create(['item_id' => $movement->item_id, 'location_id' => $movement->from_location_id,
            'stock_movement_id' => $movement->id, 'quantity_issued' => 3, 'unit_cost' => 1, 'total_cost' => 3]);
        $url = '/api/v1/inventory/stock-movements/'.$movement->hash_id.'/handover';
        $this->actingAs($actor)->postJson($url, ['received_by_employee_id' => $recipient->hash_id, 'expected_quantity' => '3'])->assertOk();
        $this->getJson('/api/v1/inventory/picking-lists/mis/'.$slip->hash_id)->assertOk()
            ->assertJsonPath('data.lines.0.handover.recipient_name', $recipient->full_name);
        MaterialHandover::query()->delete();
        $slip->update(['status' => 'cancelled']);
        $this->postJson($url, ['received_by_employee_id' => $recipient->hash_id, 'expected_quantity' => '3'])->assertConflict();
        $this->assertDatabaseCount('material_handovers', 0);
    }
}
