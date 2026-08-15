<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillPayment;
use App\Modules\Accounting\Models\Collection;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Assets\Models\Asset;
use App\Modules\HR\Models\Clearance;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\TransferOrder;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The single allow-list for polymorphic accounting and inventory references.
 * Keep this list additive: changing a mapping would make historical references
 * resolve to a different business object.
 */
final class SourceReferenceRegistry
{
    /** @var array<string, array{model: class-string<Model>|null, nullable_id?: bool}> */
    private const TYPES = [
        'bill' => ['model' => Bill::class],
        'bill_payment' => ['model' => BillPayment::class],
        'collection' => ['model' => Collection::class],
        'credit_note' => ['model' => CreditNote::class],
        'invoice' => ['model' => Invoice::class],
        'payroll_period' => ['model' => PayrollPeriod::class],
        'journal_entry_reversal' => ['model' => JournalEntry::class],
        'asset_depreciation' => ['model' => null, 'nullable_id' => true],
        // Explicit system-origin root used when inventory is first loaded.
        // It has no parent row by design, but remains bounded by this allow-list.
        'opening' => ['model' => null, 'nullable_id' => true],
        'goods_receipt_note' => ['model' => GoodsReceiptNote::class],
        'stock_movement' => ['model' => StockMovement::class],
        'material_issue_slip' => ['model' => MaterialIssueSlip::class],
        'stock_transfer' => ['model' => TransferOrder::class],
        'stock_adjustment' => ['model' => StockAdjustment::class],
        'stock_count_session' => ['model' => StockCountSession::class],
        'material_review_record' => ['model' => MaterialReviewRecord::class],
        'work_order' => ['model' => WorkOrder::class],
        'work_order_output' => ['model' => WorkOrderOutput::class],
        'return_request' => ['model' => ReturnRequest::class],
        'maintenance_work_order' => ['model' => MaintenanceWorkOrder::class],
        // Existing writers use these model class names as their type.
        Asset::class => ['model' => Asset::class],
        Clearance::class => ['model' => Clearance::class],
    ];

    /** @return array<string, array{model: class-string<Model>|null, nullable_id?: bool}> */
    public static function types(): array
    {
        return self::TYPES;
    }

    public static function assertValid(?string $type, ?int $id): void
    {
        if ($type === null && $id === null) {
            return;
        }
        if ($type === null || ! isset(self::TYPES[$type])) {
            throw new BusinessRuleException('Source reference type is not allow-listed.');
        }
        $definition = self::TYPES[$type];
        if ($id === null) {
            if (($definition['nullable_id'] ?? false) === true) {
                return;
            }
            throw new BusinessRuleException("Source reference '{$type}' requires an id.");
        }
        $model = $definition['model'];
        if ($model === null || ! $model::query()->whereKey($id)->exists()) {
            throw new BusinessRuleException("Source reference '{$type}#{$id}' cannot be resolved.");
        }
    }

    /**
     * Enumerate legacy rows that no longer resolve. This is read-only and is
     * intentionally tolerant of malformed historical values.
     *
     * @return array<int, array{ledger:string,id:int,reference_type:?string,reference_id:?int,reason:string}>
     */
    public static function reconcile(): array
    {
        $orphans = [];
        foreach ([['journal_entries', 'id'], ['stock_movements', 'id']] as [$table, $key]) {
            DB::table($table)->where(function ($query) {
                $query->whereNotNull('reference_type')->orWhereNotNull('reference_id');
            })->orderBy($key)->chunkById(500, function ($rows) use (&$orphans, $table) {
                foreach ($rows as $row) {
                    try {
                        self::assertValid($row->reference_type, $row->reference_id === null ? null : (int) $row->reference_id);
                    } catch (BusinessRuleException $e) {
                        $orphans[] = [
                            'ledger' => $table,
                            'id' => (int) $row->id,
                            'reference_type' => $row->reference_type,
                            'reference_id' => $row->reference_id === null ? null : (int) $row->reference_id,
                            'reason' => $e->getMessage(),
                        ];
                    }
                }
            }, $key);
        }
        return $orphans;
    }
}
