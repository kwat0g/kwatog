<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\PortalContractTemplate;
use App\Modules\B2B\Services\PortalContractPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalContractTemplateController
{
    public function __construct(private readonly PortalContractPdfService $pdfs) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['realm' => ['nullable', 'in:customer,supplier']]);
        $query = PortalContractTemplate::query()->with('createdBy:id,name')->orderBy('realm')->orderBy('name');
        if (isset($data['realm'])) {
            $query->where('realm', $data['realm']);
        }

        return response()->json(['data' => $query->get()->map(fn (PortalContractTemplate $template): array => $this->serialize($template))]);
    }

    public function inspect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'realm' => ['required', 'in:customer,supplier'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);
        $file = $request->file('file');
        $this->assertPdfUpload($file->getRealPath());
        $fields = $this->discoverOrAttachmentOnly($file->getRealPath());

        return response()->json(['data' => [
            'pdf_fields' => $fields,
            'auto_fill_available' => $fields !== [],
            'realm' => $data['realm'],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'realm' => ['required', 'in:customer,supplier'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'field_mappings' => ['sometimes', 'array', 'max:100'],
            'field_mappings.*' => ['required', 'string', 'max:100'],
            'required_fields' => ['sometimes', 'array', 'max:100'],
            'required_fields.*' => ['required', 'string', 'max:255'],
        ]);
        $file = $request->file('file');
        $contents = file_get_contents($file->getRealPath());
        if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'Choose a readable PDF document.']);
        }
        $fields = $this->discoverOrAttachmentOnly($file->getRealPath());
        $fieldMappings = $data['field_mappings'] ?? [];
        $requiredFields = $data['required_fields'] ?? [];
        $this->validateMapping($data['realm'], $fields, $fieldMappings, $requiredFields);

        $filename = $this->safeFilename($file->getClientOriginalName());
        $path = 'portal-contract-templates/'.$data['realm'].'/'.Str::uuid().'.pdf';
        $disk = Storage::disk('local');
        if (! $disk->put($path, $contents, ['visibility' => 'private'])) {
            throw new \RuntimeException('The private contract template could not be stored.');
        }
        try {
            $template = PortalContractTemplate::query()->create([
                'name' => $data['name'],
                'realm' => $data['realm'],
                'file_path' => $path,
                'filename' => $filename,
                'size' => strlen($contents),
                'pdf_fields' => $fields,
                'field_mappings' => $fieldMappings,
                'required_fields' => $requiredFields,
                'version' => 1,
                'source_sha256' => hash('sha256', $contents),
                'created_by' => $request->user('sanctum')->id,
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->serialize($template)], 201);
    }

    public function update(Request $request, PortalContractTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'field_mappings' => ['required', 'array', 'max:100'],
            'field_mappings.*' => ['required', 'string', 'max:100'],
            'required_fields' => ['sometimes', 'array', 'max:100'],
            'required_fields.*' => ['required', 'string', 'max:255'],
        ]);
        $pdfFields = collect($template->pdf_fields ?? [])->pluck('name')->filter()->values()->all();
        $fieldMappings = $data['field_mappings'];
        $requiredFields = $data['required_fields'] ?? [];
        $this->validateMapping($template->realm, $template->pdf_fields ?? [], $fieldMappings, $requiredFields);

        $template->forceFill([
            'field_mappings' => $fieldMappings,
            'required_fields' => $requiredFields,
            'version' => $template->version + 1,
        ])->save();

        return response()->json(['data' => $this->serialize($template->refresh())]);
    }

    private function safeFilename(string $filename): string
    {
        $base = str_replace(['"', "\r", "\n"], '_', basename(str_replace('\\', '/', $filename)));

        return Str::limit($base, 255, '');
    }

    /** @return list<array{name: string, type: string|null}> */
    private function discoverOrAttachmentOnly(string $pdfPath): array
    {
        try {
            return $this->pdfs->discoverFields($pdfPath);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'PDF form filling is not available on this server.') {
                throw $exception;
            }

            return [];
        }
    }

    private function assertPdfUpload(string $path): void
    {
        $header = is_file($path) ? file_get_contents($path, false, null, 0, 5) : false;
        if ($header !== '%PDF-') {
            throw ValidationException::withMessages(['file' => 'Choose a readable PDF document.']);
        }
    }

    /** @param list<array{name: string, type: string|null}> $fields
     * @param  array<string, string>  $fieldMappings
     * @param  list<string>  $requiredFields
     */
    private function validateMapping(string $realm, array $fields, array $fieldMappings, array $requiredFields): void
    {
        $pdfFields = collect($fields)->pluck('name')->filter()->values()->all();
        $allowedKeys = PortalContractPdfService::allowedKeysForRealm($realm);
        foreach ($fieldMappings as $field => $recordKey) {
            if (! in_array($field, $pdfFields, true)) {
                throw ValidationException::withMessages(["field_mappings.{$field}" => 'This field was not found in the uploaded PDF.']);
            }
            if (! in_array($recordKey, $allowedKeys, true)) {
                throw ValidationException::withMessages(["field_mappings.{$field}" => 'Choose an approved customer or supplier value.']);
            }
        }
        foreach ($requiredFields as $requiredField) {
            if (! in_array($requiredField, $pdfFields, true) || ! array_key_exists($requiredField, $fieldMappings)) {
                throw ValidationException::withMessages(['required_fields' => 'Each required PDF field must exist and have a mapped record value.']);
            }
        }
        if ($pdfFields === [] && ($fieldMappings !== [] || $requiredFields !== [])) {
            throw ValidationException::withMessages(['field_mappings' => 'This PDF has no fillable fields and must be attached as-is.']);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(PortalContractTemplate $template): array
    {
        return [
            'id' => $template->hash_id,
            'name' => $template->name,
            'realm' => $template->realm,
            'filename' => $template->filename,
            'pdf_fields' => $template->pdf_fields ?? [],
            'field_mappings' => $template->field_mappings ?? [],
            'required_fields' => $template->required_fields ?? [],
            'version' => (int) $template->version,
            'auto_fill_available' => count($template->pdf_fields ?? []) > 0,
            'created_by' => $template->createdBy?->name,
            'created_at' => $template->created_at?->toIso8601String(),
        ];
    }
}
