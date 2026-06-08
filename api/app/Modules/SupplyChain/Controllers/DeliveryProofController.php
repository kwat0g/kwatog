<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Resources\DeliveryProofResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ADV7 — Proof of Delivery file management.
 *
 * Each delivery may have many proofs (signed DRs, photos, customer PO
 * confirmations). Files are stored on the LOCAL disk (never public) and served
 * only through the permission-gated view() action.  Direct /storage/ access is
 * intentionally impossible for these sensitive documents.
 */
class DeliveryProofController
{
    /** GET /supply-chain/deliveries/{delivery}/proofs */
    public function index(Delivery $delivery): AnonymousResourceCollection
    {
        $proofs = $delivery->proofs()->with('uploader')->orderByDesc('created_at')->get();
        // Ensure delivery is loaded so the resource can build view URLs.
        $proofs->each(fn ($p) => $p->setRelation('delivery', $delivery));
        return DeliveryProofResource::collection($proofs);
    }

    /** POST /supply-chain/deliveries/{delivery}/proofs */
    public function store(Request $request, Delivery $delivery): JsonResponse
    {
        $validated = $request->validate([
            'proof_type' => ['required', 'string', Rule::in(['signed_dr', 'photo', 'customer_po_confirmation', 'coc', 'other'])],
            'file'       => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic,webp', 'max:10240'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('file');
        $dir = "deliveries/{$delivery->id}/proofs";
        $path = $file->store($dir, 'local');

        $proof = DeliveryProof::create([
            'delivery_id' => $delivery->id,
            'proof_type'  => $validated['proof_type'],
            'file_name'   => $file->getClientOriginalName(),
            'file_path'   => $path,
            'file_size'   => $file->getSize(),
            'mime_type'   => $file->getMimeType(),
            'uploaded_by' => $request->user()->id,
            'notes'       => $validated['notes'] ?? null,
        ]);

        $proof->load('uploader');
        $proof->setRelation('delivery', $delivery);

        return (new DeliveryProofResource($proof))->response()->setStatusCode(201);
    }

    /** GET /supply-chain/deliveries/{delivery}/proofs/{proof}/view */
    public function view(Delivery $delivery, DeliveryProof $proof): StreamedResponse
    {
        if ($proof->delivery_id !== $delivery->id) {
            throw new RuntimeException('Proof does not belong to this delivery.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($proof->file_path)) {
            throw new RuntimeException('Proof file not found on disk.');
        }

        $contents = $disk->get($proof->file_path);
        $mime = $proof->mime_type ?? $disk->mimeType($proof->file_path) ?? 'application/octet-stream';
        $isImage = str_starts_with($mime, 'image/');

        return response()->stream(
            fn () => print $contents,
            200,
            [
                'Content-Type'        => $mime,
                'Cache-Control'       => 'private, no-store, max-age=0',
                'Content-Disposition' => $isImage
                    ? sprintf('inline; filename="%s"', $proof->file_name)
                    : sprintf('attachment; filename="%s"', $proof->file_name),
            ],
        );
    }

    /** DELETE /supply-chain/deliveries/{delivery}/proofs/{proof} */
    public function destroy(Delivery $delivery, DeliveryProof $proof): JsonResponse
    {
        if ($proof->delivery_id !== $delivery->id) {
            throw new RuntimeException('Proof does not belong to this delivery.');
        }

        // Once a delivery has been confirmed, removing the last proof would
        // leave the confirmation undefensible. Block deletion in that case.
        $remaining = $delivery->proofs()->where('id', '!=', $proof->id)->count();
        $status = $delivery->status instanceof \BackedEnum ? $delivery->status->value : $delivery->status;
        if ($status === 'confirmed' && $remaining === 0) {
            return response()->json([
                'message' => 'Cannot delete the only proof of a confirmed delivery.',
            ], 422);
        }

        Storage::disk('local')->delete($proof->file_path);
        $proof->delete();

        return response()->json([], 204);
    }
}
