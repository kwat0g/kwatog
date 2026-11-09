<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\PortalContractTemplate;
use App\Modules\B2B\Models\SupplierAgreementDocument;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Services\PortalContractPdfService;
use App\Modules\B2B\Services\PortalContractSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierAgreementDocumentController
{
    public function __construct(
        private readonly PortalContractPdfService $pdfs,
        private readonly PortalContractSignatureService $signatures,
    ) {}

    public function internalIndex(Vendor $vendor): JsonResponse
    {
        $documents = SupplierAgreementDocument::query()
            ->where('vendor_id', $vendor->id)
            ->with(['uploadedBy:id,name', 'signedBy:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupplierAgreementDocument $document): array => $this->serialize($document));

        return response()->json(['data' => $documents]);
    }

    public function internalDownload(Vendor $vendor, SupplierAgreementDocument $document)
    {
        abort_unless((int) $document->vendor_id === (int) $vendor->id, 404);

        return $this->downloadPrivate($document);
    }

    public function issue(Request $request, Vendor $vendor, SupplierAgreementDocument $document): JsonResponse
    {
        abort_unless((int) $document->vendor_id === (int) $vendor->id, 404);
        $issuer = $request->user('sanctum');
        abort_unless($issuer instanceof \App\Modules\Auth\Models\User, 401);
        $issued = $this->signatures->issueSupplierDocument($document, $issuer);

        return response()->json(['data' => $this->serialize($issued->load(['uploadedBy:id,name', 'issuedBy:id,name']))]);
    }

    public function storeForVendor(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:10240', 'required_without:template_id'],
            'template_id' => ['nullable', 'string', 'required_without:file'],
        ]);
        if ($request->hasFile('file') === filled($data['template_id'] ?? null)) {
            throw ValidationException::withMessages(['file' => 'Attach one PDF or choose one contract template.']);
        }

        $template = null;
        if ($request->hasFile('file')) {
            $upload = $request->file('file');
            $content = file_get_contents($upload->getRealPath());
            $filename = $this->safeFilename($upload->getClientOriginalName());
        } else {
            $template = $this->findTemplate((string) $data['template_id']);
            $content = $this->filledTemplate($template, $this->vendorValues($vendor));
            $filename = $this->safeFilename(pathinfo($template->filename, PATHINFO_FILENAME).'-supplier-agreement.pdf');
        }
        if (! is_string($content) || ! str_starts_with($content, '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'The uploaded or generated file is not a readable PDF.']);
        }

        $path = 'supplier-agreement-documents/'.$vendor->hash_id.'/'.Str::uuid().'.pdf';
        $disk = Storage::disk('local');
        if (! $disk->put($path, $content, ['visibility' => 'private'])) {
            throw new \RuntimeException('The supplier agreement PDF could not be stored.');
        }

        try {
            $document = DB::transaction(fn (): SupplierAgreementDocument => SupplierAgreementDocument::query()->create([
                'vendor_id' => $vendor->id,
                'template_id' => $template?->id,
                'template_version' => $template?->version,
                'file_path' => $path,
                'filename' => $filename,
                'size' => strlen($content),
                'mime' => 'application/pdf',
                'document_sha256' => hash('sha256', $content),
                'signature_status' => 'draft',
                'uploaded_by' => $request->user('sanctum')->id,
            ]));
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->serialize($document->load(['uploadedBy:id,name', 'signedBy:id,name']))], 201);
    }

    public function portalIndex(Request $request): JsonResponse
    {
        $user = $this->portalUser($request);
        $documents = SupplierAgreementDocument::query()
            ->where('vendor_id', $user->vendor_id)
            ->whereIn('signature_status', ['pending', 'signed'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupplierAgreementDocument $document): array => $this->serialize($document));

        return response()->json([
            'data' => $documents,
            'can_sign' => (bool) $user->is_authorized_signatory,
            'signer_name' => $user->name,
        ]);
    }

    public function portalDownload(Request $request, string $document)
    {
        $user = $this->portalUser($request);
        $record = $this->findPortalDocument($document, (int) $user->vendor_id);

        return $this->downloadPrivate($record);
    }

    public function sign(Request $request, string $document): JsonResponse
    {
        $user = $this->portalUser($request);
        $data = $request->validate(['typed_name' => ['required', 'string', 'max:200']]);
        $record = $this->findPortalDocument($document, (int) $user->vendor_id);
        $signed = $this->signatures->signSupplierDocument($record, $user, $data['typed_name']);

        return response()->json(['data' => $this->serialize($signed)]);
    }

    /** @param array<string, scalar|null> $values */
    private function filledTemplate(PortalContractTemplate $template, array $values): string
    {
        $generatedPath = $this->pdfs->fill($template, $values);
        try {
            $content = file_get_contents($generatedPath);
            if (! is_string($content)) {
                throw new \RuntimeException('The filled supplier agreement could not be read.');
            }

            return $content;
        } finally {
            if (is_file($generatedPath)) {
                @unlink($generatedPath);
            }
        }
    }

    private function findTemplate(string $hash): PortalContractTemplate
    {
        $id = PortalContractTemplate::tryDecodeHash($hash);
        if ($id === null && app()->environment('testing') && ctype_digit($hash)) {
            $id = (int) $hash;
        }
        abort_if($id === null, 404);

        return PortalContractTemplate::query()->whereKey($id)->where('realm', 'supplier')->firstOrFail();
    }

    /** @return array<string, scalar|null> */
    private function vendorValues(Vendor $vendor): array
    {
        return [
            'vendor_name' => $vendor->name,
            'vendor_contact_name' => $vendor->contact_person,
            'vendor_email' => $vendor->email,
            'vendor_phone' => $vendor->phone,
            'vendor_address' => $vendor->address,
            'payment_terms' => $vendor->payment_terms_days === null ? null : $vendor->payment_terms_days.' days',
            'effective_date' => null,
            'expiry_date' => null,
        ];
    }

    private function findPortalDocument(string $hash, int $vendorId): SupplierAgreementDocument
    {
        $id = SupplierAgreementDocument::tryDecodeHash($hash);
        if ($id === null && app()->environment('testing') && ctype_digit($hash)) {
            $id = (int) $hash;
        }
        abort_if($id === null, 404);

        return SupplierAgreementDocument::query()
            ->whereKey($id)
            ->where('vendor_id', $vendorId)
            ->whereIn('signature_status', ['pending', 'signed'])
            ->firstOrFail();
    }

    private function portalUser(Request $request): SupplierPortalUser
    {
        $user = $request->user('supplier_portal');
        abort_unless($user instanceof SupplierPortalUser, 401);

        return $user;
    }

    private function downloadPrivate(SupplierAgreementDocument $document)
    {
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->filename, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function safeFilename(string $filename): string
    {
        $base = str_replace(["\"", "\r", "\n"], '_', basename(str_replace('\\', '/', $filename)));

        return Str::limit($base, 255, '');
    }

    /** @return array<string, mixed> */
    private function serialize(SupplierAgreementDocument $document): array
    {
        return [
            'id' => $document->hash_id,
            'file_name' => $document->filename,
            'file_size' => (int) $document->size,
            'uploaded_by' => $document->uploadedBy?->name,
            'uploaded_at' => $document->created_at?->toIso8601String(),
            'signature_status' => $document->signature_status,
            'issued_by' => $document->issuedBy?->name,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'signed_by' => $document->signedBy?->name,
            'signed_name' => $document->signed_name,
            'signed_at' => $document->signed_at?->toIso8601String(),
            'template_version' => $document->template_version,
        ];
    }
}
