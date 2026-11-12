<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductBlueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductBlueprintController
{
    public function index(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->blueprints->map(fn (ProductBlueprint $blueprint): array => $this->serialize($blueprint))->values()]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $request->merge([
            'title' => trim((string) $request->input('title', '')),
            'revision' => trim((string) $request->input('revision', '')),
        ]);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'revision' => [
                'required', 'string', 'max:40',
                Rule::unique('product_blueprints', 'revision')->where(fn ($query) => $query
                    ->where('product_id', $product->id)
                    ->where('title', trim((string) $request->input('title')))),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg,webp', 'max:20480'],
        ]);
        $file = $request->file('file');
        $contents = file_get_contents($file->getRealPath());
        abort_unless(is_string($contents) && $contents !== '', 422, 'Choose a readable blueprint file.');
        if ($file->getMimeType() === 'application/pdf' && ! str_starts_with($contents, '%PDF-')) {
            abort(422, 'Choose a readable PDF blueprint.');
        }
        $extension = strtolower($file->extension() ?: 'bin');
        $path = 'product-blueprints/'.$product->hash_id.'/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('local')->put($path, $contents, ['visibility' => 'private'])) {
            abort(500, 'Unable to store the product blueprint.');
        }

        try {
            $blueprint = DB::transaction(function () use ($product, $data, $file, $contents, $path, $request): ProductBlueprint {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $duplicate = $locked->blueprints()
                    ->where('title', $data['title'])
                    ->where('revision', $data['revision'])
                    ->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['revision' => 'This title and revision already exist for the product.']);
                }

                return $locked->blueprints()->create([
                    'title' => $data['title'],
                    'revision' => $data['revision'],
                    'notes' => isset($data['notes']) ? trim($data['notes']) : null,
                    'file_path' => $path,
                    'filename' => $this->safeFilename($file->getClientOriginalName()),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => (int) $file->getSize(),
                    'sha256' => hash('sha256', $contents),
                    'uploaded_by' => $request->user()->id,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->serialize($blueprint)], 201);
    }

    public function show(Product $product, ProductBlueprint $blueprint): StreamedResponse
    {
        abort_unless((int) $blueprint->product_id === (int) $product->id, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($blueprint->file_path), 404, 'Product blueprint not found.');

        return response()->stream(
            fn () => print $disk->get($blueprint->file_path),
            200,
            [
                'Content-Type' => $blueprint->mime,
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Disposition' => sprintf('attachment; filename="%s"', basename($blueprint->file_path)),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function destroy(Product $product, ProductBlueprint $blueprint): JsonResponse
    {
        abort_unless((int) $blueprint->product_id === (int) $product->id, 404);
        $path = $blueprint->file_path;
        DB::transaction(function () use ($product, $blueprint): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $record = $locked->blueprints()->lockForUpdate()->findOrFail($blueprint->id);
            $visible = $locked->portal_visibility === 'standard' || $locked->portalCustomers()->exists();
            if ($visible && $locked->is_customizable && $locked->blueprints()->count() <= 1) {
                abort(422, 'A customizable product must keep at least one approved blueprint while it is listed.');
            }
            $record->delete();
        });
        Storage::disk('local')->delete($path);

        return response()->json(['message' => 'Product blueprint removed.']);
    }

    /** @return array<string, mixed> */
    private function serialize(ProductBlueprint $blueprint): array
    {
        return [
            'id' => $blueprint->hash_id,
            'title' => $blueprint->title,
            'revision' => $blueprint->revision,
            'notes' => $blueprint->notes,
            'filename' => $blueprint->filename,
            'mime' => $blueprint->mime,
            'size' => (int) $blueprint->size,
            'sha256' => $blueprint->sha256,
            'download_url' => "/api/v1/crm/products/{$blueprint->product->hash_id}/blueprints/{$blueprint->hash_id}/file",
            'uploaded_by' => $blueprint->uploadedBy?->name,
            'created_at' => optional($blueprint->created_at)->toIso8601String(),
        ];
    }

    private function safeFilename(string $filename): string
    {
        return Str::limit(str_replace(["\r", "\n", '"'], '_', basename(str_replace('\\', '/', $filename))), 255, '');
    }
}
