<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\CustomerAgreementDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomerAgreementDocumentController
{
    public function index(Customer $customer): JsonResponse
    {
        $documents = CustomerAgreementDocument::query()
            ->where('customer_id', $customer->id)
            ->with('uploadedBy:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerAgreementDocument $document): array => $this->serialize($document));

        return response()->json(['data' => $documents]);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf', 'max:10240']]);
        $file = $request->file('file');
        $disk = Storage::disk('local');
        $path = $file->store('customer-agreement-documents/'.$customer->hash_id, 'local');
        if (! $path) {
            throw new \RuntimeException('The PDF could not be stored.');
        }

        try {
            $document = CustomerAgreementDocument::query()->create([
                'customer_id' => $customer->id,
                'file_path' => $path,
                'filename' => str_replace(["\"", "\r", "\n"], '_', basename(str_replace('\\', '/', $file->getClientOriginalName()))),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType() ?: 'application/pdf',
                'uploaded_by' => $request->user()->id,
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->serialize($document->load('uploadedBy:id,name'))], 201);
    }

    public function download(Customer $customer, CustomerAgreementDocument $document)
    {
        abort_unless((int) $document->customer_id === (int) $customer->id, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->filename, [
            'Content-Type' => $document->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function serialize(CustomerAgreementDocument $document): array
    {
        return [
            'id' => $document->hash_id,
            'file_name' => $document->filename,
            'file_size' => (int) $document->size,
            'uploaded_by' => $document->uploadedBy?->name,
            'uploaded_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
