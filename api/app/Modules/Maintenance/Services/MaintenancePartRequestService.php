<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Services\NotificationService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Maintenance\Enums\MaintenanceWorkOrderStatus;
use App\Modules\Maintenance\Models\MaintenanceLog;
use App\Modules\Maintenance\Models\MaintenancePartRequest;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Models\SparePartUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenancePartRequestService
{
    public const PENDING_MESSAGE = 'Warehouse handover is pending. Confirm delivery of requested spare parts before continuing repair.';

    public function request(MaintenanceWorkOrder $order, array $data, User $actor): MaintenancePartRequest
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))', ['maintenance-part-request:'.strtolower($data['operation_key'])]);
            $order = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertOpen($order);
            $key = strtolower($data['operation_key']);
            $existing = MaintenancePartRequest::where('operation_key', $key)->first();
            if ($existing) {
                abort_if($existing->work_order_id !== $order->id || $existing->requested_by !== $actor->id
                    || $existing->item_id !== $data['item_id'] || $existing->location_id !== $data['location_id']
                    || $existing->work_log_id !== $data['work_record_id'] || bccomp($existing->quantity, (string) $data['quantity'], 3) !== 0,
                    409, 'Request key was already used with different details.');

                return $existing;
            }
            $this->recipient($order, $order->assigned_to);
            $record = MaintenanceLog::whereKey($data['work_record_id'])->where('work_order_id', $order->id)->first();
            if (! $record || ! trim((string) $record->finding) || ! trim((string) $record->action_taken)) {
                throw ValidationException::withMessages(['work_record_id' => 'Select a structured work record from this work order.']);
            }
            $item = Item::whereKey($data['item_id'])->where('is_active', true)->where('item_type', 'spare_part')->first();
            if (! $item) {
                throw ValidationException::withMessages(['item_id' => 'Select an active spare part.']);
            }
            $quantity = (string) $data['quantity'];
            if (in_array(strtoupper(trim($item->unit_of_measure)), ['PC', 'PCS', 'PIECE', 'PIECES', 'EA', 'EACH', 'UNIT', 'UNITS', 'SET', 'SETS'], true)
                && bccomp($quantity, bcadd($quantity, '0', 0), 3) !== 0) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must be a whole number for this unit of measure.']);
            }
            $level = StockLevel::with('location.zone.warehouse')->where('item_id', $item->id)->where('location_id', $data['location_id'])->first();
            if (! $level || bccomp(app(StockLocationSummaryService::class)->availability(collect([$level]))['usable_quantity'], $quantity, 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient usable stock at this location.']);
            }
            $request = MaintenancePartRequest::create(['work_order_id' => $order->id, 'work_log_id' => $record->id,
                'item_id' => $item->id, 'location_id' => $data['location_id'], 'quantity' => $quantity,
                'operation_key' => $key, 'requested_by' => $actor->id, 'status' => 'requested']);
            $order->forceFill(['parts_reconciled_at' => null, 'parts_reconciled_by' => null])->save();
            $notifications = app(NotificationService::class);
            $recipients = $notifications->usersWithPermission('inventory.issue.create')
                ->filter(fn (User $user): bool => $user->hasPermission('inventory.picking.view'))
                ->values();
            $notifications->send($recipients,
                'maintenance.parts_requested', ['title' => 'Spare parts requested', 'message' => $order->mwo_number.' requires '.$quantity.' '.$item->unit_of_measure.' of '.$item->code.'.',
                    'link_to' => '/inventory/picking?maintenance_work_order_id='.$order->hash_id], 'maintenance-part-request:'.$request->id);

            return $request;
        });
    }

    public function withdraw(MaintenanceWorkOrder $order, MaintenancePartRequest $request, User $actor): MaintenancePartRequest
    {
        return DB::transaction(function () use ($order, $request, $actor) {
            $order = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertOpen($order);
            $request = MaintenancePartRequest::lockForUpdate()->findOrFail($request->id);
            abort_unless($request->work_order_id === $order->id, 404);
            abort_if($request->status === 'handed_over', 409, 'Parts already handed over. Return unused parts instead.');
            if ($request->status !== 'withdrawn') {
                $request->update(['status' => 'withdrawn', 'withdrawn_by' => $actor->id, 'withdrawn_at' => now()]);
            }

            return $request;
        });
    }

    public function confirm(MaintenancePartRequest $request, int $recipientId, string $quantity, User $actor): MaintenancePartRequest
    {
        return DB::transaction(function () use ($request, $recipientId, $quantity, $actor) {
            $order = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($request->work_order_id);
            $request = MaintenancePartRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->status === 'handed_over') {
                $received = MaterialHandover::whereIn('stock_movement_id', SparePartUsage::where('issue_group_key', $request->operation_key)->pluck('stock_movement_id'))->get();
                abort_if($received->isEmpty() || $received->contains(fn ($row) => $row->received_by_employee_id !== $recipientId)
                    || bccomp($request->quantity, $quantity, 3) !== 0, 409, 'Handover was already recorded with different details.');

                return $request;
            }
            abort_if($request->status !== 'requested', 409, 'Request was withdrawn.');
            $this->assertOpen($order);
            $recipient = $this->recipient($order, $recipientId);
            abort_if(bccomp($request->quantity, $quantity, 3) !== 0, 409, 'Requested quantity changed. Reload the request.');
            $level = StockLevel::where('item_id', $request->item_id)->where('location_id', $request->location_id)->first();
            if (! $level || bccomp(bcsub($level->quantity, $level->reserved_quantity, 3), $quantity, 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient usable stock. Request remains pending.']);
            }
            try {
                app(SparePartUsageService::class)->record($order, ['item_id' => $request->item_id, 'location_id' => $request->location_id,
                    'quantity' => $request->quantity, 'work_record_id' => $request->work_log_id, 'operation_key' => $request->operation_key], $actor);
            } catch (InsufficientStockException $error) {
                throw ValidationException::withMessages(['quantity' => $error->getMessage()]);
            }
            foreach (SparePartUsage::where('issue_group_key', $request->operation_key)->get() as $usage) {
                MaterialHandover::create(['stock_movement_id' => $usage->stock_movement_id, 'received_by_employee_id' => $recipient->id,
                    'recipient_name' => $recipient->full_name, 'recorded_by' => $actor->id, 'quantity' => $usage->quantity, 'handed_over_at' => now()]);
            }
            $request->update(['status' => 'handed_over', 'handed_over_at' => now()]);
            $user = $recipient->user;
            if ($user) {
                app(NotificationService::class)->send($user, 'maintenance.parts_handed_over', ['title' => 'Spare parts handed over',
                    'message' => 'Warehouse confirmed parts for '.$order->mwo_number.'. Repair can continue.',
                    'link_to' => ($user->role?->slug === 'employee' ? '/self-service/maintenance-work-orders/' : '/maintenance/work-orders/').$order->hash_id]);
            }

            return $request;
        });
    }

    public function recipient(MaintenanceWorkOrder $order, ?int $id): Employee
    {
        $employee = $id && (int) $order->assigned_to === $id ? Employee::whereKey($id)->where('status', 'active')
            ->whereHas('department', fn ($q) => $q->where('code', 'MAINT')->where('is_active', true))
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->whereHas('role', fn ($role) => $role->whereIn('slug', ['employee', 'maintenance_tech'])))->first() : null;
        if (! $employee) {
            throw ValidationException::withMessages(['received_by_employee_id' => 'Choose the currently assigned active Maintenance technician.']);
        }

        return $employee;
    }

    public function assertOpen(MaintenanceWorkOrder $order): void
    {
        abort_if($order->status !== MaintenanceWorkOrderStatus::InProgress || $order->repair_submitted_at !== null, 409, 'Work order is not open for spare-part handover.');
    }

    public function pending(MaintenanceWorkOrder $order): int
    {
        return $order->partRequests()->where('status', 'requested')->count()
            + StockMovement::where('reference_type', 'maintenance_work_order')->where('reference_id', $order->id)->pendingHandover()->count();
    }

    public function assertReady(MaintenanceWorkOrder $order): void
    {
        if ($this->pending($order) > 0) {
            throw ValidationException::withMessages(['parts_handover' => self::PENDING_MESSAGE]);
        }
    }
}
