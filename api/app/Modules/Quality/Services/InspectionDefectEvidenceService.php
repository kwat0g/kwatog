<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionDefectEvidence;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InspectionDefectEvidenceService
{
    public function list(Inspection $inspection): Collection
    {
        return $inspection->defectEvidence()
            ->with(['grnItem.item', 'uploader'])
            ->get();
    }

    public function create(Inspection $inspection, array $data, UploadedFile $photo, User $by): InspectionDefectEvidence
    {
        $path = $photo->store("quality/inspection-defects/{$inspection->hash_id}", 'local');
        if (! $path) {
            throw ValidationException::withMessages(['photo' => ['The defect photo could not be stored. Try again.']]);
        }

        try {
            return DB::transaction(function () use ($inspection, $data, $photo, $by, $path): InspectionDefectEvidence {
                $locked = Inspection::query()->lockForUpdate()->findOrFail($inspection->id);
                if ($locked->stage !== InspectionStage::Incoming || ! $locked->grn_item_id) {
                    throw new BusinessRuleException('Defect photos can be attached only to an incoming inspection linked to a received item.');
                }
                if ($locked->status === \App\Modules\Quality\Enums\InspectionStatus::Cancelled) {
                    throw new BusinessRuleException('Defect evidence cannot be added to a cancelled inspection.');
                }

                $receivedItem = GrnItem::query()->lockForUpdate()->findOrFail($locked->grn_item_id);
                $affected = (string) $data['affected_quantity'];
                if (bccomp($affected, '0', 3) <= 0 || bccomp($affected, (string) $receivedItem->quantity_received, 3) > 0) {
                    throw ValidationException::withMessages(['affected_quantity' => ['Affected quantity must be greater than zero and cannot exceed the received quantity.']]);
                }

                return InspectionDefectEvidence::query()->create([
                    'inspection_id' => $locked->id,
                    // Always take the received-item link from the locked
                    // inspection. Never trust a client-supplied GRN item ID.
                    'grn_item_id' => $receivedItem->id,
                    'category' => $data['category'],
                    'other_category' => $data['other_category'] ?? null,
                    'severity' => $data['severity'],
                    'affected_quantity' => $affected,
                    'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
                    'file_path' => $path,
                    'file_name' => mb_substr(basename($photo->getClientOriginalName()), 0, 255),
                    'mime_type' => $photo->getMimeType() ?: 'application/octet-stream',
                    'file_size' => $photo->getSize(),
                    'uploaded_by' => $by->id,
                ])->load(['grnItem.item', 'uploader']);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function photo(InspectionDefectEvidence $evidence): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($evidence->file_path), 404);

        return Storage::disk('local')->response(
            $evidence->file_path,
            $evidence->file_name,
            [
                'Content-Type' => $evidence->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }
}
