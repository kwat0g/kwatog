<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Enums\DeliveryProofType;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Services\DeliveryService;
use App\Modules\SupplyChain\Resources\DeliveryProofResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
        $proofs = $delivery->proofs()->withTrashed()->with('uploader')->orderByDesc('created_at')->get();
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

        if ($validated['proof_type'] === DeliveryProofType::LoadingPhoto->value) {
            $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,heic,webp', 'max:10240']]);
        }
        $proof = app(DeliveryService::class)->uploadProof(
            $delivery, $request->file('file'), DeliveryProofType::from($validated['proof_type']),
            $request->user(), $validated['notes'] ?? null,
        );

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
        try {
            app(DeliveryService::class)->archiveProof($delivery, $proof);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([], 204);
    }

    /** POST /supply-chain/deliveries/{delivery}/proofs/{proof}/restore */
    public function restore(Delivery $delivery, DeliveryProof $proof): JsonResponse
    {
        app(DeliveryService::class)->restoreProof($delivery, $proof);

        return response()->json(['message' => 'Delivery proof restored.']);
    }
}
