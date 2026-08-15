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
            'etd' => $data['etd'] ?? null,
            'eta' => $data['eta'] ?? null,
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

            return $this->show($locked);
        });
    }

    /**
     * Patch carrier/vessel/dates without changing status. Useful for ImpEx
     * Officer correcting tracking metadata mid-flight.
     */
    public function updateMeta(Shipment $s, array $data): Shipment
    {
        $allowed = ['carrier', 'vessel', 'container_number', 'bl_number', 'etd', 'eta', 'notes'];
        $patch = array_intersect_key($data, array_flip($allowed));
        if (empty($patch)) {
            return $this->show($s);
        }
        $s->forceFill($patch)->save();

        return $this->show($s);
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
            return DB::transaction(fn () => ShipmentDocument::create([
                'shipment_id' => $s->id,
                'document_type' => $type->value,
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'notes' => $notes,
                'uploaded_by' => $by->id,
                'uploaded_at' => now(),
            ])->load('uploader:id,name,role_id'));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function deleteDocument(ShipmentDocument $doc): void
    {
        $path = $doc->file_path;
        DB::transaction(function () use ($doc, $path) {
            $doc->delete();
            DB::afterCommit(function () use ($path): void {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
            });
        });
    }

    public function delete(Shipment $s): void
    {
        if ($s->status === ShipmentStatus::Received) {
            throw new BusinessRuleException('Cannot delete a received shipment.');
        }
        DB::transaction(function () use ($s) {
            $paths = $s->documents->pluck('file_path')->filter()->values()->all();
            $s->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
        });
    }
}
