<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\StoreSupplierListingRequest;
use App\Modules\B2B\Requests\Supplier\UpdateSupplierListingRequest;
use App\Modules\B2B\Resources\SupplierListingResource;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Purchasing\Services\SupplierListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class SupplierListingPortalController extends Controller
{
    public function __construct(
        private readonly SupplierListingService $service,
    ) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');

        return $user;
    }

    /**
     * GET /api/v1/b2b/supplier/item-catalog
     * Read-only Ogami item catalog suppliers anchor their offers against.
     */
    public function catalog(): JsonResponse
    {
        $items = $this->service->catalog();

        return response()->json(['data' => $items->map(fn ($item) => [
            'id' => $item->hash_id,
            'code' => $item->code,
            'name' => $item->name,
            'unit_of_measure' => $item->unit_of_measure,
        ])->values()]);
    }

    /**
     * GET /api/v1/b2b/supplier/item-listings
     * The supplier's own listings, scoped by vendor.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->service->listForVendor($this->user($request)->vendor_id, [
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return SupplierListingResource::collection($paginator);
    }

    /**
     * POST /api/v1/b2b/supplier/item-listings
     */
    public function store(StoreSupplierListingRequest $request): JsonResponse
    {
        $listing = $this->service->submit($this->user($request)->vendor_id, $request->validated());

        return (new SupplierListingResource($listing))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PUT /api/v1/b2b/supplier/item-listings/{listing}
     * Editable only while pending; tenancy scope owns the row.
     */
    public function update(UpdateSupplierListingRequest $request, SupplierItemListing $supplierItemListing): SupplierListingResource
    {
        $user = $this->user($request);
        // Ownership is enforced explicitly: implicit binding resolves before
        // the tenancy global scope is guaranteed to be registered, so the
        // bound row must be checked here (same defense-in-depth as the PO
        // endpoints' service ownership checks).
        if ($supplierItemListing->vendor_id !== $user->vendor_id) {
            abort(404);
        }

        $listing = $this->service->update($supplierItemListing, $request->validated());

        return new SupplierListingResource($listing);
    }
}
