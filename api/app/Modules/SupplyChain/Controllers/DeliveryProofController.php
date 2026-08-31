<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Enums\DeliveryProofType;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Resources\DeliveryProofResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
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
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'proof_types' => array_map(
                static fn (DeliveryProofType $type): array => ['value' => $type->value, 'label' => $type->label()],
                DeliveryProofType::cases(),
            ),
        ]]);
    }
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
            'proof_type' => ['required', Rule::enum(DeliveryProofType::class)],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic,webp', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $request->file('file');
        $dir = "deliveries/{$delivery->id}/proofs";
        $path = $file->store($dir, 'local');
        if ($path === false) {
            throw new BusinessRuleException('Unable to store delivery proof.');
        }

        try {
            $proof = DeliveryProof::create([
                'delivery_id' => $delivery->id,
                'proof_type' => $validated['proof_type'],
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_by' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        $proof->load('uploader');
        $proof->setRelation('delivery', $delivery);

        return (new DeliveryProofResource($proof))->response()->setStatusCode(201);
    }

    /** GET /supply-chain/deliveries/{delivery}/proofs/{proof}/view */
    public function view(Delivery $delivery, DeliveryProof $proof): StreamedResponse
    {
        if ($proof->delivery_id !== $delivery->id) {
            abort(404, 'Proof does not belong to this delivery.');
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($proof->file_path)) {
            abort(404, 'Proof file not found on disk.');
        }

        $contents = $disk->get($proof->file_path);
        $mime = $proof->mime_type ?? $disk->mimeType($proof->file_path) ?? 'application/octet-stream';
        $isImage = str_starts_with($mime, 'image/');

        return response()->stream(
            fn () => print $contents,
            200,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Disposition' => self::contentDisposition(
                    $isImage ? 'inline' : 'attachment',
                    (string) $proof->file_name,
                ),
            ],
        );
    }

    /**
     * M044 — `file_name` is the client's original upload name, stored verbatim.
     * Interpolating it straight into the header let a name containing a double
     * quote terminate the `filename` parameter early (measured:
     * `inline; filename="a".jpg"`), which forges the rest of the header value.
     * Build an RFC 6266 disposition instead: a sanitised ASCII `filename` for
     * old clients plus a percent-encoded UTF-8 `filename*` for the real name.
     *
     * The character class is written with hex escapes and a doubled backslash on
     * purpose — the obvious `/[\r\n"\\]/` collapses to `[\r\n"\]` in a
     * single-quoted PHP string, PCRE reads `\]` as a literal `]`, the class never
     * closes, preg_replace() returns null, and every download becomes a 500.
     * The customer portal stream paid for that lesson already.
     */
    private static function contentDisposition(string $type, string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '', $name) ?: 'delivery-proof';
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'delivery-proof';

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $type,
            $ascii,
            rawurlencode($name),
        );
    }

    /** DELETE /supply-chain/deliveries/{delivery}/proofs/{proof} */
    public function destroy(Delivery $delivery, DeliveryProof $proof): JsonResponse
    {
        if ($proof->delivery_id !== $delivery->id) {
            throw new RuntimeException('Proof does not belong to this delivery.');
        }

        try {
            $path = DB::transaction(function () use ($delivery, $proof): string {
                // M044 — the "is this the last proof" count used to be taken
                // BEFORE the transaction and without a lock, so two concurrent
                // deletes against two proofs each saw one proof remaining, each
                // passed the guard, and both committed: a confirmed delivery
                // with zero proofs. confirm() already serializes on this row, so
                // taking the same lock here puts both operations on one queue.
                $locked = Delivery::query()->lockForUpdate()->find($delivery->id);
                if (! $locked) {
                    throw new BusinessRuleException('Delivery not found.');
                }

                $target = DeliveryProof::query()->lockForUpdate()->find($proof->id);
                if (! $target) {
                    throw new BusinessRuleException('Delivery proof not found.');
                }

                // Once a delivery has been confirmed, removing the last proof
                // would leave the confirmation undefensible.
                $remaining = $locked->proofs()->where('id', '!=', $target->id)->count();
                $status = $locked->status instanceof \BackedEnum ? $locked->status->value : $locked->status;
                if ($status === 'confirmed' && $remaining === 0) {
                    throw new BusinessRuleException('Cannot delete the only proof of a confirmed delivery.');
                }

                $path = (string) $target->file_path;
                $target->delete();
                DB::afterCommit(fn () => Storage::disk('local')->delete($path));

                return $path;
            });
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([], 204);
    }

    /** POST /supply-chain/deliveries/{delivery}/proofs/{proof}/restore */
    public function restore(Delivery $delivery, DeliveryProof $proof): JsonResponse
    {
        if ($proof->delivery_id !== $delivery->id) {
            throw new RuntimeException('Proof does not belong to this delivery.');
        }

        $proof->restore();
        return response()->json(['message' => 'Delivery proof restored.']);
    }
}
