<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\ProductImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductImageController
{
    /** Compatibility endpoint: add the image to the gallery and make it primary. */
    public function upload(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ]);
        $image = $this->storeImage($product, $request->file('image'), $data['alt_text'] ?? null, true);

        return response()->json(['data' => [
            'image_url' => "/api/v1/crm/products/{$product->hash_id}/image",
            'image' => $this->serialize($product, $image),
        ]]);
    }

    public function uploadMany(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:10'],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'alt_texts' => ['sometimes', 'array', 'max:10'],
            'alt_texts.*' => ['nullable', 'string', 'max:255'],
        ]);
        $storedPaths = [];
        try {
            $product = DB::transaction(function () use ($product, $data, &$storedPaths): Product {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $existingCount = $locked->images()->count();
                if ($existingCount + count($data['images']) > 10) {
                    abort(422, 'A product can have up to 10 gallery images. Remove an image before adding more.');
                }
                $makeFirstPrimary = $existingCount === 0;
                $position = (int) ($locked->images()->max('position') ?? -1) + 1;
                foreach ($data['images'] as $index => $file) {
                    $path = $this->storePath($file);
                    $storedPaths[] = $path;
                    $image = $locked->images()->create([
                        'file_path' => $path,
                        'filename' => $this->safeFilename($file->getClientOriginalName()),
                        'mime' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => (int) $file->getSize(),
                        'position' => $position + $index,
                        'alt_text' => $data['alt_texts'][$index] ?? null,
                        'is_primary' => $makeFirstPrimary && $index === 0,
                    ]);
                    if ($makeFirstPrimary && $index === 0) {
                        $locked->forceFill(['image_path' => $path])->save();
                    }
                }

                return $locked->fresh()->load(['images', 'blueprints']);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPaths);
            throw $exception;
        }

        return response()->json(['data' => [
            'image_url' => $product->image_path ? "/api/v1/crm/products/{$product->hash_id}/image" : null,
            'images' => $product->images->map(fn (ProductImage $image) => $this->serialize($product, $image))->values(),
        ]], 201);
    }

    public function setPrimary(Product $product, ProductImage $image): JsonResponse
    {
        abort_unless((int) $image->product_id === (int) $product->id, 404);
        DB::transaction(function () use ($product, $image): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $selected = $locked->images()->lockForUpdate()->findOrFail($image->id);
            $locked->images()->update(['is_primary' => false]);
            $selected->update(['is_primary' => true]);
            $locked->forceFill(['image_path' => $selected->file_path])->save();
        });
        $product->refresh();

        return response()->json(['data' => [
            'image_url' => "/api/v1/crm/products/{$product->hash_id}/image",
            'images' => $product->load('images')->images->map(fn (ProductImage $record) => $this->serialize($product, $record))->values(),
        ]]);
    }

    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        abort_unless((int) $image->product_id === (int) $product->id, 404);
        $oldPath = $image->file_path;
        DB::transaction(function () use ($product, $image): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $selected = $locked->images()->lockForUpdate()->findOrFail($image->id);
            $wasPrimary = $selected->is_primary || $locked->image_path === $selected->file_path;
            $selected->delete();
            if ($wasPrimary) {
                $next = $locked->images()->orderBy('position')->orderBy('id')->first();
                $locked->images()->update(['is_primary' => false]);
                if ($next) {
                    $next->update(['is_primary' => true]);
                }
                $locked->forceFill(['image_path' => $next?->file_path])->save();
            }
        });
        Storage::disk('local')->delete($oldPath);

        return response()->json(['message' => 'Product image removed.']);
    }

    public function show(Product $product): StreamedResponse
    {
        abort_unless($product->image_path, 404, 'No image for this product.');
        $image = $product->images()->where('file_path', $product->image_path)->first();

        return $this->stream($image?->file_path ?? $product->image_path, $image?->mime);
    }

    public function showImage(Product $product, ProductImage $image): StreamedResponse
    {
        abort_unless((int) $image->product_id === (int) $product->id, 404);

        return $this->stream($image->file_path, $image->mime);
    }

    public function showForCustomer(Request $request, Product $product): StreamedResponse
    {
        $customer = $request->user('customer_portal');
        abort_unless($customer && $product->isAvailableToCustomer((int) $customer->customer_id), 404);
        abort_unless($product->image_path, 404, 'No image for this product.');

        $image = $product->images()->where('file_path', $product->image_path)->first();

        return $this->stream($image?->file_path ?? $product->image_path, $image?->mime);
    }

    public function showForCustomerGallery(Request $request, Product $product, ProductImage $image): StreamedResponse
    {
        $customer = $request->user('customer_portal');
        abort_unless($customer && $product->isAvailableToCustomer((int) $customer->customer_id), 404);
        abort_unless((int) $image->product_id === (int) $product->id, 404);

        return $this->stream($image->file_path, $image->mime);
    }

    /** @return array<string, mixed> */
    private function serialize(Product $product, ProductImage $image): array
    {
        return [
            'id' => $image->hash_id,
            'url' => "/api/v1/crm/products/{$product->hash_id}/images/{$image->hash_id}/file",
            'filename' => $image->filename,
            'mime' => $image->mime,
            'size' => (int) $image->size,
            'position' => (int) $image->position,
            'alt_text' => $image->alt_text,
            'is_primary' => (bool) $image->is_primary,
        ];
    }

    private function storeImage(Product $product, UploadedFile $file, ?string $altText, bool $makePrimary): ProductImage
    {
        $path = $this->storePath($file);
        try {
            return DB::transaction(function () use ($product, $file, $path, $altText, $makePrimary): ProductImage {
                $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
                $count = $locked->images()->count();
                if ($count >= 10) {
                    abort(422, 'A product can have up to 10 gallery images. Remove an image before adding more.');
                }
                $makePrimary = $makePrimary || $count === 0;
                $position = (int) ($locked->images()->max('position') ?? -1) + 1;
                if ($makePrimary) {
                    $locked->images()->update(['is_primary' => false]);
                }
                $image = $locked->images()->create([
                    'file_path' => $path,
                    'filename' => $this->safeFilename($file->getClientOriginalName()),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => (int) $file->getSize(),
                    'position' => $position,
                    'alt_text' => $altText,
                    'is_primary' => $makePrimary,
                ]);
                if ($makePrimary) {
                    $locked->forceFill(['image_path' => $path])->save();
                }

                return $image;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    private function storePath(UploadedFile $file): string
    {
        $extension = strtolower($file->extension() ?: 'bin');
        $path = 'product-images/'.Str::uuid().'.'.$extension;
        if (! $file->storeAs('product-images', basename($path), 'local')) {
            abort(500, 'Unable to store product image.');
        }

        return $path;
    }

    private function safeFilename(string $filename): string
    {
        return Str::limit(str_replace(["\r", "\n", '"'], '_', basename(str_replace('\\', '/', $filename))), 255, '');
    }

    private function stream(string $path, ?string $mime = null): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404, 'Product image not found on disk.');

        return response()->stream(
            fn () => print $disk->get($path),
            200,
            [
                'Content-Type' => $mime ?: $disk->mimeType($path) ?: 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Disposition' => sprintf('inline; filename="%s"', basename($path)),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
