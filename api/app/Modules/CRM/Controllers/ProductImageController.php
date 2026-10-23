<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductImageController
{
    public function upload(Request $request, Product $product): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        $path = $request->file('image')->store('product-images', 'local');
        abort_if($path === false, 500, 'Unable to store product image.');
        $previousPath = $product->image_path;

        try {
            DB::transaction(fn () => $product->update(['image_path' => $path]));
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        if ($previousPath) {
            Storage::disk('local')->delete($previousPath);
        }

        return response()->json(['data' => ['image_url' => "/api/v1/crm/products/{$product->hash_id}/image"]]);
    }

    public function show(Product $product): StreamedResponse
    {
        return $this->stream($product);
    }

    public function showForCustomer(Request $request, Product $product): StreamedResponse
    {
        $customer = $request->user('customer_portal');
        abort_unless($customer && $product->isAvailableToCustomer((int) $customer->customer_id), 404);

        return $this->stream($product);
    }

    private function stream(Product $product): StreamedResponse
    {
        abort_unless($product->image_path, 404, 'No image for this product.');

        $disk = Storage::disk('local');
        abort_unless($disk->exists($product->image_path), 404, 'Product image not found on disk.');

        $contents = $disk->get($product->image_path);

        return response()->stream(
            fn () => print $contents,
            200,
            [
                'Content-Type' => $disk->mimeType($product->image_path) ?? 'application/octet-stream',
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Disposition' => sprintf('inline; filename="%s"', basename($product->image_path)),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
