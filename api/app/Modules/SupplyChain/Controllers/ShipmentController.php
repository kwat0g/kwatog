<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Enums\ShipmentDocumentType;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Enums\Incoterm;
use App\Modules\SupplyChain\Enums\LandedCostAllocationMethod;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Requests\CreateShipmentRequest;
use App\Modules\SupplyChain\Resources\ShipmentDocumentResource;
use App\Modules\SupplyChain\Resources\ShipmentResource;
use App\Modules\SupplyChain\Services\LandedCostService;
use App\Modules\SupplyChain\Services\ShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentController
{
    public function __construct(
        private readonly ShipmentService $service,
        private readonly LandedCostService $landedCostService,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(
                static function (ShipmentStatus $status): array {
                    $next = collect(ShipmentStatus::cases())
                        ->first(fn (ShipmentStatus $candidate): bool => $status->canTransitionTo($candidate));
                    return [
                        'value' => $status->value,
                        'label' => $status->label(),
                        'next_status' => $next?->value,
                        'is_terminal' => $status->isTerminal(),
                    ];
                },
                ShipmentStatus::cases(),
            ),
            'document_types' => array_map(
                static fn (ShipmentDocumentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                ShipmentDocumentType::cases(),
            ),
            'incoterms' => array_map(
                static fn (Incoterm $term): array => ['value' => $term->value, 'label' => $term->label()],
                Incoterm::cases(),
            ),
            'allocation_methods' => array_map(
                static fn (LandedCostAllocationMethod $method): array => ['value' => $method->value, 'label' => $method->label()],
                LandedCostAllocationMethod::cases(),
            ),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ShipmentResource::collection($this->service->list($request->query()));
    }

    public function show(Shipment $shipment): ShipmentResource
    {
        return new ShipmentResource($this->service->show($shipment));
    }

    public function store(CreateShipmentRequest $request): ShipmentResource
    {
        return new ShipmentResource($this->service->create($request->validated(), $request->user()));
    }

    public function updateStatus(Request $request, Shipment $shipment): ShipmentResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ShipmentStatus::values())],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);
        return new ShipmentResource($this->service->updateStatus(
            $shipment,
            ShipmentStatus::from((string) $data['status']),
            $data['note'] ?? null,
        ));
    }

    public function updateMeta(Request $request, Shipment $shipment): ShipmentResource
    {
        $data = $request->validate([
            'carrier'          => ['nullable', 'string', 'max:100'],
            'vessel'           => ['nullable', 'string', 'max:100'],
            'container_number' => ['nullable', 'string', 'max:32'],
            'bl_number'        => ['nullable', 'string', 'max:32'],
            'etd'              => ['nullable', 'date'],
            // CreateShipmentRequest enforces ETA >= ETD; this path did not, so a
            // patch could leave a shipment arriving before it departed. Compare
            // against the submitted ETD when one is present, otherwise against
            // the value already on the record — a partial patch of ETA alone must
            // still be checked.
            'eta'              => ['nullable', 'date', function (string $attribute, mixed $value, callable $fail) use ($request, $shipment): void {
                if ($value === null) {
                    return;
                }
                $etd = $request->has('etd')
                    ? $request->input('etd')
                    : optional($shipment->etd)?->toDateString();
                if ($etd !== null && $etd !== '' && strtotime((string) $value) < strtotime((string) $etd)) {
                    $fail('ETA cannot be before ETD.');
                }
            }],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);
        return new ShipmentResource($this->service->updateMeta($shipment, $data));
    }

    public function uploadDocument(Request $request, Shipment $shipment): ShipmentDocumentResource
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(ShipmentDocumentType::values())],
            'file'          => [
                'required', 'file', 'mimes:pdf,jpg,jpeg,png,xlsx,csv', 'max:20480', // 20 MB
                // `shipment_documents.original_filename` is varchar(255) and
                // ShipmentService::uploadDocument() writes the client name
                // verbatim. Measured: a 304-character filename reached Postgres
                // as SQLSTATE 22001 and surfaced as a 500. Refuse it here.
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }
                    if (mb_strlen((string) $value->getClientOriginalName()) > 255) {
                        $fail('The file name may not be greater than 255 characters.');
                    }
                },
            ],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);
        $doc = $this->service->uploadDocument(
            $shipment,
            $request->file('file'),
            ShipmentDocumentType::from((string) $data['document_type']),
            $request->user(),
            $data['notes'] ?? null,
        );
        return new ShipmentDocumentResource($doc);
    }

    /**
     * Stream a shipment document to the authenticated user.
     * Files live on the local disk and are NEVER served via a public URL.
     * Route must be protected by permission:supply_chain.view (see routes.php).
     */
    public function downloadDocument(ShipmentDocument $document): StreamedResponse
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($document->file_path)) {
            abort(404, 'Shipment document file not found on disk.');
        }

        $contents = $disk->get($document->file_path);
        $mime     = $document->mime_type ?? $disk->mimeType($document->file_path) ?? 'application/octet-stream';
        $isImage  = str_starts_with($mime, 'image/');
        $filename = $document->original_filename ?? basename($document->file_path);

        return response()->stream(
            fn () => print $contents,
            200,
            [
                'Content-Type'        => $mime,
                'Cache-Control'       => 'private, no-store, max-age=0',
                'Content-Disposition' => self::contentDisposition(
                    $isImage ? 'inline' : 'attachment',
                    (string) $filename,
                ),
            ],
        );
    }

    /**
     * M043 — `original_filename` is the client's upload name, stored verbatim, and
     * it used to be interpolated straight into the header. Measured: a document
     * uploaded as `bl".pdf` produced `attachment; filename="bl".pdf"`, where the
     * double quote closes the `filename` parameter early and the remainder is
     * injected into the header value. Build an RFC 6266 disposition instead — a
     * sanitised ASCII `filename` for old clients plus a percent-encoded UTF-8
     * `filename*` for the real name. Same shape and same reasoning as
     * `DeliveryProofController::contentDisposition()`.
     *
     * The character class is written with hex escapes and a doubled backslash on
     * purpose: the obvious `/[\r\n"\\]/` collapses to `[\r\n"\]` in a
     * single-quoted PHP string, PCRE reads `\]` as a literal `]`, the class never
     * closes, preg_replace() returns null, and every download becomes a 500.
     */
    private static function contentDisposition(string $type, string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '', $name) ?: 'shipment-document';
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'shipment-document';

        return sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $type,
            $ascii,
            rawurlencode($name),
        );
    }

    public function destroyDocument(ShipmentDocument $document): JsonResponse
    {
        $this->service->deleteDocument($document);
        return response()->json([], 204);
    }

    public function restoreDocument(ShipmentDocument $document): JsonResponse
    {
        $document->restore();
        return response()->json(['message' => 'Shipment document restored.']);
    }

    /**
     * OGAMI-104 — Calculate and persist landed cost allocations for a shipment.
     */
    public function calculateLandedCost(Request $request, Shipment $shipment): ShipmentResource
    {
        $data = $request->validate([
            'allocation_method' => ['nullable', 'string', Rule::in(LandedCostAllocationMethod::values())],
        ]);

        return new ShipmentResource(
            $this->landedCostService->calculate($shipment, $data['allocation_method'] ?? null)
        );
    }

    public function destroy(Shipment $shipment): JsonResponse
    {
        $this->service->delete($shipment);
        return response()->json([], 204);
    }

    public function restore(Shipment $shipment): JsonResponse
    {
        $shipment->restore();
        return response()->json(['message' => 'Shipment restored.']);
    }
}
