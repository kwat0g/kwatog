<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\UploadSupplierListingImageRequest;
use App\Modules\B2B\Resources\SupplierListingResource;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Purchasing\Services\SupplierListingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupplierListingImageController
{
    public function __construct(private readonly SupplierListingService $service) {}

    public function upload(UploadSupplierListingImageRequest $request, SupplierItemListing $supplierItemListing): SupplierListingResource
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');
        abort_unless((int) $supplierItemListing->vendor_id === (int) $user->vendor_id, 404);

        return new SupplierListingResource($this->service->setImage($supplierItemListing, $request->file('image')));
    }

    public function portalImage(Request $request, SupplierItemListing $supplierItemListing)
    {
        /** @var SupplierPortalUser|null $user */
        $user = $request->user('supplier_portal');
        abort_unless($user && (int) $supplierItemListing->vendor_id === (int) $user->vendor_id, 404);

        return $this->download($supplierItemListing);
    }

    public function internalImage(SupplierItemListing $supplierItemListing)
    {
        return $this->download($supplierItemListing);
    }

    private function download(SupplierItemListing $listing)
    {
        abort_unless($listing->image_path && Storage::disk('local')->exists($listing->image_path), 404);

        return Storage::disk('local')->response(
            $listing->image_path,
            $listing->image_original_filename ?: 'supplier-listing-image',
            ['Content-Type' => $listing->image_mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            'inline',
        );
    }
}
