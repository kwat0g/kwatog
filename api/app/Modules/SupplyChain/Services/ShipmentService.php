<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Enums\ShipmentDocumentType;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Models\Container;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sprint 7 — Task 65. Inbound shipment lifecycle service.
 *
 * Owns:
 *   create(po)             — opens a shipment in `ordered` status
 *   updateStatus(s, next)  — enforces allowed forward transitions
 *   uploadDocument(s, ...) — persists file + metadata; idempotent per type
 *   updateMeta(...)        — patches carrier/vessel/dates without status change
 */
class ShipmentService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly GrnService $grns,
    ) {}

    public function list(array $filters): LengthAwarePaginator
    {
        $q = Shipment::query()->with([
            'purchaseOrder:id,po_number,vendor_id',
            'creator:id,name,role_id',
        ]);

        TrashedFilter::apply($q, $filters);

        foreach (['status'] as $f) {
            if (! empty($filters[$f])) {
                $q->where($f, $filters[$f]);
            }
        }
        if (! empty($filters['purchase_order_id'])) {
            // ShipmentController::index() forwards the raw query bag. A (int) cast
            // on a hash yields 0, so the list came back empty instead of filtered.
            $q->where('purchase_order_id', HashIdFilter::decode($filters['purchase_order_id'], PurchaseOrder::class) ?? 0);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('shipment_number', SearchOperator::like(), $term)
                ->orWhere('container_number', SearchOperator::like(), $term)
                ->orWhere('bl_number', SearchOperator::like(), $term));
        }

        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(Shipment $s): Shipment
    {
        return $s->load([
            'purchaseOrder:id,po_number,vendor_id',
            'creator:id,name,role_id',
            'documents' => fn ($q) => $q->with('uploader:id,name,role_id')->orderBy('uploaded_at'),
            // ShipmentLandedCostResource is exposed via whenLoaded('landedCosts'),
            // so without this the detail endpoint always returned it as null and
            // the allocated costs were invisible to the UI.
            'landedCosts.shipment',
            'landedCosts.purchaseOrderItem',
            'goodsReceiptNote:id,grn_number,shipment_id,status,received_date',
        ]);
    }

    /**
     * @param array{
     *   purchase_order_id: int,
     *   carrier?: string|null,
     *   vessel?: string|null,
     *   container_number?: string|null,
     *   bl_number?: string|null,
     *   etd?: string|null,
     *   eta?: string|null,
     *   freight_cost?: string|null,
     *   insurance_cost?: string|null,
     *   duties_amount?: string|null,
     *   brokerage_fee?: string|null,
     *   other_charges?: string|null,
     *   notes?: string|null
     * } $data
     */
    public function create(array $data, User $by): Shipment
    {
        $po = PurchaseOrder::query()->findOrFail((int) $data['purchase_order_id']);

        return DB::transaction(fn () => $this->show(Shipment::create([
            'shipment_number' => $this->sequences->generate('shipment'),
            'purchase_order_id' => $po->id,
            'status' => ShipmentStatus::Ordered->value,
            'carrier' => $data['carrier'] ?? null,
            'vessel' => $data['vessel'] ?? null,
            'container_number' => $data['container_number'] ?? null,
            'bl_number' => $data['bl_number'] ?? null,
            // CreateShipmentRequest validates `incoterm` against the Incoterm
            // enum and the SPA create form submits it, but it was never copied
            // into the payload: the caller got a 201 with the value silently
            // gone and the column left null, so customs paperwork fell back to
            // the PO's term or a blank.
            //
            // Persisting the submitted value is not the same as deciding whether
            // a shipment term OVERRIDES or INHERITS the PO's — the generated PDFs
            // still render `$po->incoterm`, and reconciling those two is left to
            // the trade-document work in the action plan.
            'incoterm' => $data['incoterm'] ?? null,
            'etd' => $data['etd'] ?? null,
            'eta' => $data['eta'] ?? null,
            'freight_cost' => $data['freight_cost'] ?? '0.00',
            'insurance_cost' => $data['insurance_cost'] ?? '0.00',
            'duties_amount' => $data['duties_amount'] ?? '0.00',
            'brokerage_fee' => $data['brokerage_fee'] ?? '0.00',
            'other_charges' => $data['other_charges'] ?? '0.00',
            'notes' => $data['notes'] ?? null,
            'created_by' => $by->id,
        ])));
    }

    public function updateStatus(Shipment $s, ShipmentStatus $next, ?string $note = null): Shipment
    {
        return DB::transaction(function () use ($s, $next, $note) {
            // Lock-then-guard: re-read so a stale transition cannot overwrite a
            // shipment a concurrent update already advanced (same pattern as
            // DeliveryService::updateStatus).
            $locked = Shipment::query()->lockForUpdate()->findOrFail($s->getKey());
            $current = $locked->status instanceof ShipmentStatus ? $locked->status : ShipmentStatus::from((string) $locked->status);
            if (! $current->canTransitionTo($next)) {
                throw new BusinessRuleException("Cannot transition shipment {$locked->shipment_number} from {$current->value} to {$next->value}.");
            }
            if ($next === ShipmentStatus::Cleared && $current === ShipmentStatus::Customs) {
                $this->assertClearanceEvidence($locked);
            }
            $patch = ['status' => $next->value];
            // Auto-stamp date columns at known transitions.
            $today = now()->toDateString();
            if ($next === ShipmentStatus::Shipped && ! $locked->atd) {
                $patch['atd'] = $today;
            }
            if ($next === ShipmentStatus::Cleared && ! $locked->customs_clearance_date) {
                $patch['customs_clearance_date'] = $today;
            }
            if ($next === ShipmentStatus::Received && ! $locked->ata) {
                $patch['ata'] = $today;
            }
            if ($note) {
                $patch['notes'] = trim(($locked->notes ? $locked->notes."\n" : '').'['.$next->value.'] '.$note);
            }
            $locked->forceFill($patch)->save();

            if ($next === ShipmentStatus::Received) {
                $this->grns->stageForShipment($locked);
            }

            return $this->show($locked);
        });
    }

    /**
     * Patch carrier/vessel/dates without changing status. Useful for ImpEx
     * Officer correcting tracking metadata mid-flight.
     */
    public function updateMeta(Shipment $s, array $data): Shipment
    {
        return DB::transaction(function () use ($s, $data): Shipment {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($s->id);
            $allowed = [
                'carrier', 'vessel', 'container_number', 'bl_number', 'etd', 'eta', 'notes',
                'freight_cost', 'insurance_cost', 'duties_amount', 'brokerage_fee', 'other_charges',
            ];
            $patch = array_intersect_key($data, array_flip($allowed));
            $landedKeys = [
                'freight_cost', 'insurance_cost', 'duties_amount', 'brokerage_fee', 'other_charges',
            ];
            $costPatch = array_intersect(array_keys($patch), $landedKeys);
            $identityPatch = array_diff(array_keys($patch), $landedKeys);
            if ($identityPatch !== [] && $this->isEvidenceFrozen($locked)) {
                throw new BusinessRuleException('Shipment metadata is frozen after customs clearance.');
            }
            if ($costPatch !== [] && GoodsReceiptNote::query()
                ->where('shipment_id', $locked->id)
                ->whereHas('items', fn ($q) => $q->where('quantity_accepted', '>', 0))
                ->exists()) {
                throw new BusinessRuleException(
                    'Landed cost cannot be changed after this shipment has an accepted receipt.'
                );
            }
            if ($patch !== []) {
                $locked->forceFill($patch)->save();
            }

            return $this->show($locked);
        });
    }

    /**
     * Upload (or replace) a document of a given type. Files are stored
     * under storage/app/shipments/{shipment_id}/ on the LOCAL disk (never
     * public) and served only through a permission-gated controller action.
     */
    public function uploadDocument(
        Shipment $s,
        UploadedFile $file,
        ShipmentDocumentType $type,
        User $by,
        ?string $notes = null,
    ): ShipmentDocument {
        $folder = "shipments/{$s->id}";
        $path = $file->store($folder, 'local');
        if ($path === false) {
            throw new BusinessRuleException('Unable to store shipment document.');
        }

        try {
            return DB::transaction(function () use ($s, $file, $type, $by, $notes, $path): ShipmentDocument {
                $lockedShipment = Shipment::query()->lockForUpdate()->findOrFail($s->id);
                $this->assertDocumentsMutable($lockedShipment);
                $existing = ShipmentDocument::query()
                    ->where('shipment_id', $lockedShipment->id)
                    ->where('document_type', $type->value)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $oldPath = $existing->file_path;
                    $existing->forceFill([
                        'file_path' => $path,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size_bytes' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                        'notes' => $notes,
                        'uploaded_by' => $by->id,
                        'uploaded_at' => now(),
                    ])->save();
                    DB::afterCommit(function () use ($oldPath, $path): void {
                        if ($oldPath && $oldPath !== $path) {
                            Storage::disk('local')->delete($oldPath);
                        }
                    });

                    return $existing->load('uploader:id,name,role_id');
                }

                return ShipmentDocument::create([
                    'shipment_id' => $lockedShipment->id,
                    'document_type' => $type->value,
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_size_bytes' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'notes' => $notes,
                    'uploaded_by' => $by->id,
                    'uploaded_at' => now(),
                ])->load('uploader:id,name,role_id');
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function deleteDocument(ShipmentDocument $doc): void
    {
        DB::transaction(function () use ($doc): void {
            $locked = ShipmentDocument::query()->lockForUpdate()->findOrFail($doc->id);
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($locked->shipment_id);
            $this->assertDocumentsMutable($shipment);
            // Soft-delete is recoverable archive. The private blob remains
            // available for the restore route and audit evidence.
            $locked->delete();
        });
    }

    public function delete(Shipment $s): void
    {
        DB::transaction(function () use ($s) {
            $locked = Shipment::query()->lockForUpdate()->findOrFail($s->id);
            if ($this->isArchiveBlocked($locked)) {
                throw new BusinessRuleException('Cannot archive a customs or received shipment; preserve the customs record instead.');
            }
            $locked->delete();
        });
    }

    public function restore(Shipment $shipment): Shipment
    {
        return DB::transaction(function () use ($shipment): Shipment {
            $locked = Shipment::withTrashed()->lockForUpdate()->findOrFail($shipment->id);
            if (! $locked->trashed()) {
                throw new BusinessRuleException('Shipment is not archived.');
            }
            if ($this->isArchiveBlocked($locked)) {
                throw new BusinessRuleException('Archived customs and terminal shipments cannot be restored.');
            }
            $locked->restore();

            return $this->show($locked);
        });
    }

    public function restoreDocument(ShipmentDocument $document): ShipmentDocument
    {
        return DB::transaction(function () use ($document): ShipmentDocument {
            $locked = ShipmentDocument::withTrashed()->lockForUpdate()->findOrFail($document->id);
            $shipment = Shipment::withTrashed()->lockForUpdate()->findOrFail($locked->shipment_id);
            $this->assertDocumentsMutable($shipment);
            if (! Storage::disk('local')->exists((string) $locked->file_path)) {
                throw new BusinessRuleException('The archived shipment document file is missing and cannot be restored.');
            }
            $locked->restore();

            return $locked->load('uploader:id,name,role_id');
        });
    }

    private function isEvidenceFrozen(Shipment $shipment): bool
    {
        $status = $shipment->status instanceof ShipmentStatus
            ? $shipment->status
            : ShipmentStatus::from((string) $shipment->status);

        return in_array($status, [ShipmentStatus::Cleared, ShipmentStatus::Received, ShipmentStatus::Cancelled], true);
    }

    private function isArchiveBlocked(Shipment $shipment): bool
    {
        $status = $shipment->status instanceof ShipmentStatus
            ? $shipment->status
            : ShipmentStatus::from((string) $shipment->status);

        return in_array($status, [ShipmentStatus::Customs, ShipmentStatus::Cleared, ShipmentStatus::Received, ShipmentStatus::Cancelled], true);
    }

    private function assertDocumentsMutable(Shipment $shipment): void
    {
        if ($this->isEvidenceFrozen($shipment)) {
            throw new BusinessRuleException('Shipment documents are frozen after customs clearance.');
        }
    }

    private function assertClearanceEvidence(Shipment $shipment): void
    {
        $required = [
            ShipmentDocumentType::BillOfLading->value,
            ShipmentDocumentType::CommercialInvoice->value,
            ShipmentDocumentType::PackingList->value,
            ShipmentDocumentType::ImportEntry->value,
            ShipmentDocumentType::BocRelease->value,
        ];
        $present = $shipment->documents()
            ->whereNull('deleted_at')
            ->whereIn('document_type', $required)
            ->pluck('document_type')
            ->map(static fn ($type): string => $type instanceof \BackedEnum ? $type->value : (string) $type)
            ->all();
        $missing = array_values(array_diff($required, $present));
        if ($missing !== []) {
            throw new BusinessRuleException(
                'Cannot clear shipment customs: required documents are missing ('.implode(', ', $missing).').'
            );
        }
        if (! Container::query()->where('shipment_id', $shipment->id)->whereNull('deleted_at')->exists()) {
            throw new BusinessRuleException('Cannot clear shipment customs: at least one active container is required.');
        }
    }
}
