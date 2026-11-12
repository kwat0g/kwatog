<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\PortalContractTemplate;
use App\Modules\B2B\Services\PortalContractPdfService;
use App\Modules\B2B\Services\PortalContractSignatureService;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use App\Modules\CRM\Models\CustomerProductWarrantyOption;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PriceAgreementTerminationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerAgreementDocumentController
{
    public function __construct(
        private readonly PortalContractPdfService $pdfs,
        private readonly PortalContractSignatureService $signatures,
        private readonly PriceAgreementTerminationService $terminations,
    ) {}

    public function index(Customer $customer): JsonResponse
    {
        $documents = CustomerAgreementDocument::query()
            ->where('customer_id', $customer->id)
            ->with(['uploadedBy:id,name', 'signedBy:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerAgreementDocument $document): array => $this->serialize($document));

        return response()->json(['data' => $documents]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:10240', 'required_without:template_id'],
            'template_id' => ['nullable', 'string', 'required_without:file'],
        ]);
        if ($request->hasFile('file') === filled($data['template_id'] ?? null)) {
            throw ValidationException::withMessages(['file' => 'Attach one PDF or choose one contract template.']);
        }

        $disk = Storage::disk('local');
        $template = null;
        $content = null;
        $filename = '';
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $content = file_get_contents($file->getRealPath());
            $filename = $this->safeFilename($file->getClientOriginalName());
        } else {
            $template = $this->findTemplate((string) $data['template_id'], 'customer');
            $content = $this->filledTemplate($template, $this->customerValues($customer));
            $filename = $this->safeFilename(pathinfo($template->filename, PATHINFO_FILENAME).'-'.$customer->code.'-agreement.pdf');
        }
        if (! is_string($content) || ! str_starts_with($content, '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'The uploaded or generated file is not a readable PDF.']);
        }

        $path = 'customer-agreement-documents/'.$customer->hash_id.'/'.Str::uuid().'.pdf';
        if (! $disk->put($path, $content, ['visibility' => 'private'])) {
            throw new \RuntimeException('The customer agreement PDF could not be stored.');
        }

        try {
            $document = DB::transaction(fn (): CustomerAgreementDocument => CustomerAgreementDocument::query()->create([
                'customer_id' => $customer->id,
                'file_path' => $path,
                'filename' => $filename,
                'size' => strlen($content),
                'mime' => 'application/pdf',
                'uploaded_by' => $request->user('sanctum')->id,
                'document_sha256' => hash('sha256', $content),
                'signature_status' => 'draft',
                'template_id' => $template?->id,
                'template_version' => $template?->version,
            ]));
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        } finally {
            if (isset($generatedPath) && is_file($generatedPath)) {
                @unlink($generatedPath);
            }
        }

        return response()->json(['data' => $this->serialize($document->load(['uploadedBy:id,name', 'signedBy:id,name']))], 201);
    }

    public function download(Customer $customer, CustomerAgreementDocument $document)
    {
        abort_unless((int) $document->customer_id === (int) $customer->id, 404);

        return $this->downloadPrivate($document->file_path, $document->filename, $document->mime);
    }

    public function issue(Request $request, Customer $customer, CustomerAgreementDocument $document): JsonResponse
    {
        abort_unless((int) $document->customer_id === (int) $customer->id, 404);
        $issuer = $request->user('sanctum');
        abort_unless($issuer instanceof User, 401);
        $issued = $this->signatures->issueCustomerDocument($document, $issuer);

        return response()->json(['data' => $this->serialize($issued->load(['uploadedBy:id,name', 'issuedBy:id,name']))]);
    }

    public function portalIndex(Request $request): JsonResponse
    {
        $user = $this->portalUser($request);
        $documents = CustomerAgreementDocument::query()
            ->where('customer_id', $user->customer_id)
            ->whereIn('signature_status', ['pending', 'signed'])
            ->whereNotNull('issued_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerAgreementDocument $document): array => $this->serialize($document));

        return response()->json([
            'data' => $documents,
            'can_sign' => (bool) $user->is_authorized_signatory,
            'signer_name' => $user->name,
        ]);
    }

    public function portalDownload(Request $request, string $document)
    {
        $user = $this->portalUser($request);
        $record = $this->findCustomerPortalDocument($document, (int) $user->customer_id);

        return $this->downloadPrivate($record->file_path, $record->filename, $record->mime);
    }

    public function sign(Request $request, string $document): JsonResponse
    {
        $user = $this->portalUser($request);
        $data = $request->validate(['typed_name' => ['required', 'string', 'max:200']]);
        $record = $this->findCustomerPortalDocument($document, (int) $user->customer_id);
        $signed = DB::transaction(function () use ($record, $user, $data): CustomerAgreementDocument {
            $signed = $this->signatures->signCustomerDocument($record, $user, $data['typed_name']);
            $this->terminations->applySignedDocument($signed, $user);

            return $signed;
        });

        return response()->json(['data' => $this->serialize($signed)]);
    }

    /** @param array<string, scalar|null> $values */
    private function filledTemplate(PortalContractTemplate $template, array $values): string
    {
        $generatedPath = $this->pdfs->fill($template, $values);
        try {
            $content = file_get_contents($generatedPath);
            if (! is_string($content)) {
                throw new \RuntimeException('The filled customer agreement could not be read.');
            }

            return $content;
        } finally {
            if (is_file($generatedPath)) {
                @unlink($generatedPath);
            }
        }
    }

    private function findTemplate(string $hash, string $realm): PortalContractTemplate
    {
        $id = PortalContractTemplate::tryDecodeHash($hash);
        if ($id === null && app()->environment('testing') && ctype_digit($hash)) {
            $id = (int) $hash;
        }
        abort_if($id === null, 404);

        return PortalContractTemplate::query()->whereKey($id)->where('realm', $realm)->firstOrFail();
    }

    /** @return array<string, scalar|null> */
    private function customerValues(Customer $customer): array
    {
        $latitude = $customer->delivery_latitude;
        $longitude = $customer->delivery_longitude;
        $mapPin = is_numeric($latitude) && is_numeric($longitude)
            && (float) $latitude >= -90 && (float) $latitude <= 90
            && (float) $longitude >= -180 && (float) $longitude <= 180
                ? sprintf('%.7f, %.7f', (float) $latitude, (float) $longitude)
                : null;

        return [
            'customer_name' => $customer->name,
            'customer_contact_name' => $customer->contact_person,
            'customer_contact_role' => $customer->contact_person_role,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'customer_address' => $customer->address,
            'delivery_address' => $customer->delivery_address,
            'delivery_map_pin' => $mapPin,
            'payment_terms' => $customer->payment_terms_days === null ? null : $customer->payment_terms_days.' days',
            'allowed_payment_methods' => $this->paymentMethodsSummary($customer),
            'shipping_payer' => $customer->shipping_payer === null ? null : ucfirst($customer->shipping_payer),
            'effective_date' => $customer->agreement_starts_on?->format('Y-m-d'),
            'expiry_date' => $customer->agreement_ends_on?->format('Y-m-d'),
            'allowed_products_summary' => $this->allowedProductsSummary($customer),
            'warranty_summary' => $this->warrantySummary($customer),
        ];
    }

    private function paymentMethodsSummary(Customer $customer): ?string
    {
        if ($customer->allowed_payment_methods === null) {
            return 'All supported payment methods';
        }

        $labels = ['cash' => 'Cash', 'check' => 'Check', 'bank_transfer' => 'Bank transfer'];
        $methods = collect($customer->allowed_payment_methods)
            ->map(static fn (string $method): ?string => $labels[$method] ?? null)
            ->filter()
            ->values();

        return $methods->isEmpty() ? null : $methods->implode(', ');
    }

    private function allowedProductsSummary(Customer $customer): ?string
    {
        $products = $customer->portalProducts()
            ->where('products.is_active', true)
            ->get(['products.id', 'products.part_number', 'products.name'])
            ->keyBy('id');

        if ($customer->portal_catalog_mode === 'standard') {
            $standardProducts = Product::query()
                ->where('is_active', true)
                ->where('portal_visibility', 'standard')
                ->get(['id', 'part_number', 'name']);
            $products = $products->union($standardProducts->keyBy('id'));
        }

        $summary = $products
            ->sortBy(static fn (Product $product): string => $product->part_number)
            ->map(static fn (Product $product): string => $product->part_number.' · '.$product->name)
            ->values()
            ->implode("\n");

        return $summary === '' ? null : $summary;
    }

    private function warrantySummary(Customer $customer): ?string
    {
        $options = $customer->warrantyOptions()
            ->with('product:id,part_number,name')
            ->orderBy('product_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
        $summaryRows = $options->map(fn (CustomerProductWarrantyOption $option): string => $this->warrantyOptionSummary($option));
        $fallback = $this->fallbackWarrantySummary($customer);
        if ($fallback !== null) {
            $summaryRows->push('Customer fallback (only where no product option applies): '.$fallback);
        }

        $summary = $summaryRows->filter()->implode("\n");

        return $summary === '' ? null : $summary;
    }

    private function warrantyOptionSummary(CustomerProductWarrantyOption $option): string
    {
        $product = $option->product;
        $productLabel = $product ? $product->part_number.' · '.$product->name : 'Product';
        $terms = [$option->label];
        $terms[] = $option->warranty_duration_days === null
            ? 'duration to be agreed'
            : $option->warranty_duration_days.' days';
        if ($option->warranty_start_basis !== null) {
            $terms[] = 'from '.$option->warranty_start_basis;
        }
        if ($option->warranty_report_window_days !== null) {
            $terms[] = 'report within '.$option->warranty_report_window_days.' days';
        }

        $issueLabels = [
            'manufacturing_defect' => 'Manufacturing defect',
            'shipping_damage' => 'Shipping damage',
            'customer_caused_damage' => 'Customer-caused damage',
        ];
        $issues = collect($option->warranty_covered_issues ?? [])
            ->map(static fn (string $issue): ?string => $issueLabels[$issue] ?? null)
            ->filter()
            ->values();
        if ($issues->isNotEmpty()) {
            $terms[] = 'covers '.$issues->implode(', ');
        }

        $remedyLabels = ['repair' => 'Repair', 'replace' => 'Replace', 'credit' => 'Credit', 'refund' => 'Refund'];
        $remedies = collect($option->warranty_remedies ?? [])
            ->map(static fn (string $remedy): ?string => $remedyLabels[$remedy] ?? null)
            ->filter()
            ->values();
        if ($remedies->isNotEmpty()) {
            $terms[] = 'remedies '.$remedies->implode(', ');
        }
        if (filled($option->additional_terms)) {
            $terms[] = 'other terms: '.$option->additional_terms;
        }

        return $productLabel.': '.implode('; ', $terms).'.';
    }

    private function fallbackWarrantySummary(Customer $customer): ?string
    {
        $terms = [];
        if ($customer->warranty_duration_days !== null) {
            $terms[] = $customer->warranty_duration_days.' days';
        }
        if ($customer->warranty_start_basis !== null) {
            $terms[] = 'from '.$customer->warranty_start_basis;
        }
        if ($customer->warranty_report_window_days !== null) {
            $terms[] = 'report within '.$customer->warranty_report_window_days.' days';
        }

        $issueLabels = [
            'manufacturing_defect' => 'Manufacturing defect',
            'shipping_damage' => 'Shipping damage',
            'customer_caused_damage' => 'Customer-caused damage',
        ];
        $issues = collect($customer->warranty_covered_issues ?? [])
            ->map(static fn (string $issue): ?string => $issueLabels[$issue] ?? null)
            ->filter()
            ->values();
        if ($issues->isNotEmpty()) {
            $terms[] = 'covers '.$issues->implode(', ');
        }

        $remedyLabels = ['repair' => 'Repair', 'replace' => 'Replace', 'credit' => 'Credit', 'refund' => 'Refund'];
        $remedies = collect($customer->warranty_remedies ?? [])
            ->map(static fn (string $remedy): ?string => $remedyLabels[$remedy] ?? null)
            ->filter()
            ->values();
        if ($remedies->isNotEmpty()) {
            $terms[] = 'remedies '.$remedies->implode(', ');
        }

        return $terms === [] ? null : implode('; ', $terms).'.';
    }

    private function findCustomerPortalDocument(string $hash, int $customerId): CustomerAgreementDocument
    {
        $id = CustomerAgreementDocument::tryDecodeHash($hash);
        if ($id === null && app()->environment('testing') && ctype_digit($hash)) {
            $id = (int) $hash;
        }
        abort_if($id === null, 404);

        return CustomerAgreementDocument::query()
            ->whereKey($id)
            ->where('customer_id', $customerId)
            ->whereIn('signature_status', ['pending', 'signed'])
            ->whereNotNull('issued_at')
            ->firstOrFail();
    }

    private function portalUser(Request $request): CustomerPortalUser
    {
        $user = $request->user('customer_portal');
        abort_unless($user instanceof CustomerPortalUser, 401);

        return $user;
    }

    private function downloadPrivate(string $path, string $filename, string $mime)
    {
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function safeFilename(string $filename): string
    {
        $base = str_replace(['"', "\r", "\n"], '_', basename(str_replace('\\', '/', $filename)));

        return Str::limit($base, 255, '');
    }

    /** @return array<string, mixed> */
    private function serialize(CustomerAgreementDocument $document): array
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
            'purpose' => str_contains($document->file_path, '/price-terminations/')
                ? 'price_agreement_termination'
                : 'customer_agreement',
        ];
    }
}
