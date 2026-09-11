<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\BulkStoreSupplierListingRequest;
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
     * Paginated (max 100/page) and searchable; defaults to purchasable items
     * (raw material + packaging) unless the caller asks for other item types.
     */
    public function catalog(Request $request): JsonResponse
    {
        $paginator = $this->service->catalogPaginated([
            'search' => $request->query('search'),
            'item_type' => $request->query('item_type'),
            'per_page' => $request->query('per_page', 25),
        ]);

        $data = collect($paginator->items())->map(fn ($item) => [
            'id' => $item->hash_id,
            'code' => $item->code,
            'name' => $item->name,
            'item_type' => $item->item_type->value,
            'unit_of_measure' => $item->unit_of_measure,
        ])->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
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
     * POST /api/v1/b2b/supplier/item-listings/bulk
     * Multi-item submission with per-row outcomes. One duplicate no longer
     * discards the whole batch; the caller sees created + failed rows.
     */
    public function storeBulk(BulkStoreSupplierListingRequest $request): JsonResponse
    {
        $result = $this->service->submitMany(
            $this->user($request)->vendor_id,
            $request->validated('items'),
        );

        $created = collect($result['created'])
            ->map(fn ($listing) => (new SupplierListingResource($listing))->resolve())
            ->values();

        return response()->json([
            'data' => [
                'created' => $created,
                'failed' => $result['failed'],
                'created_count' => $created->count(),
                'failed_count' => count($result['failed']),
            ],
        ]);
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
