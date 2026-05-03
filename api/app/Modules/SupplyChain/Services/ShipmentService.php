<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Services\DocumentSequenceService;
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
use RuntimeException;

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
            'creator:id,name',
        ]);

        foreach (['status'] as $f) if (! empty($filters[$f])) $q->where($f, $filters[$f]);
        if (! empty($filters['purchase_order_id'])) $q->where('purchase_order_id', (int) $filters['purchase_order_id']);
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $q->where(fn (Builder $b) => $b
                ->where('shipment_number', 'like', $term)
                ->orWhere('container_number', 'like', $term)
                ->orWhere('bl_number', 'like', $term));
        }
        return $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 20), 100));
    }

    public function show(Shipment $s): Shipment
    {
        return $s->load([
            'purchaseOrder:id,po_number,vendor_id',
            'creator:id,name',
            'documents' => fn ($q) => $q->with('uploader:id,name')->orderBy('uploaded_at'),
        ]);
    }

    /** @param array{purchase_order_id: int, carrier?: string|null, eta?: string|null, notes?: string|null} $data */
    public function create(array $data, User $by): Shipment
    {
        $po = PurchaseOrder::query()->findOrFail((int) $data['purchase_order_id']);
        return DB::transaction(fn () => $this->show(Shipment::create([
            'shipment_number'   => $this->sequences->generate('shipment'),
            'purchase_order_id' => $po->id,
            'status'            => ShipmentStatus::Ordered->value,
            'carrier'           => $data['carrier'] ?? null,
            'vessel'            => $data['vessel'] ?? null,
            'container_number'  => $data['container_number'] ?? null,
            'bl_number'         => $data['bl_number'] ?? null,
            'etd'               => $data['etd'] ?? null,
            'eta'               => $data['eta'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'created_by'        => $by->id,
        ])));
    }

    public function updateStatus(Shipment $s, ShipmentStatus $next, ?string $note = null): Shipment
    {
        $current = $s->status instanceof ShipmentStatus ? $s->status : ShipmentStatus::from((string) $s->status);
        if (! $current->canTransitionTo($next)) {
            throw new RuntimeException("Cannot transition shipment {$s->shipment_number} from {$current->value} to {$next->value}.");
        }
        $patch = ['status' => $next->value];
        // Auto-stamp date columns at known transitions.
        $today = now()->toDateString();
        if ($next === ShipmentStatus::Shipped && ! $s->atd)              $patch['atd'] = $today;
        if ($next === ShipmentStatus::Cleared && ! $s->customs_clearance_date) $patch['customs_clearance_date'] = $today;
        if ($next === ShipmentStatus::Received && ! $s->ata)             $patch['ata'] = $today;
        if ($note) $patch['notes'] = trim(($s->notes ? $s->notes."\n" : '').'['.$next->value.'] '.$note);
        $s->forceFill($patch)->save();
        return $this->show($s);
    }

    /**
     * Patch carrier/vessel/dates without changing status. Useful for ImpEx
     * Officer correcting tracking metadata mid-flight.
     */
    public function updateMeta(Shipment $s, array $data): Shipment
    {
        $allowed = ['carrier', 'vessel', 'container_number', 'bl_number', 'etd', 'eta', 'notes'];
        $patch = array_intersect_key($data, array_flip($allowed));
        if (empty($patch)) return $this->show($s);
        $s->forceFill($patch)->save();
        return $this->show($s);
    }

    /**
     * Upload (or replace) a document of a given type. Files are stored
     * under storage/app/public/shipments/{shipment_id}/.
     */
    public function uploadDocument(
        Shipment $s,
        UploadedFile $file,
        ShipmentDocumentType $type,
        User $by,
        ?string $notes = null,
    ): ShipmentDocument {
        return DB::transaction(function () use ($s, $file, $type, $by, $notes) {
            $folder = "shipments/{$s->id}";
            $path = $file->store($folder, 'public');
            return ShipmentDocument::create([
                'shipment_id'       => $s->id,
                'document_type'     => $type->value,
                'file_path'         => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size_bytes'   => $file->getSize(),
                'mime_type'         => $file->getMimeType(),
                'notes'             => $notes,
                'uploaded_by'       => $by->id,
                'uploaded_at'       => now(),
            ])->load('uploader:id,name');
        });
    }

    public function deleteDocument(ShipmentDocument $doc): void
    {
        DB::transaction(function () use ($doc) {
            if ($doc->file_path) {
                Storage::disk('public')->delete($doc->file_path);
            }
            $doc->delete();
        });
    }

    public function delete(Shipment $s): void
    {
        if ($s->status === ShipmentStatus::Received) {
            throw new RuntimeException('Cannot delete a received shipment.');
        }
        DB::transaction(function () use ($s) {
            foreach ($s->documents as $doc) {
                if ($doc->file_path) Storage::disk('public')->delete($doc->file_path);
            }
            $s->delete();
        });
    }
}
