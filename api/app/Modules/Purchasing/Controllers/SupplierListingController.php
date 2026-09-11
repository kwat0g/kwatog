<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Purchasing\Requests\ApproveSupplierListingRequest;
use App\Modules\Purchasing\Requests\BulkApproveSupplierListingRequest;
use App\Modules\Purchasing\Requests\BulkRejectSupplierListingRequest;
use App\Modules\Purchasing\Requests\RejectSupplierListingRequest;
use App\Modules\Purchasing\Resources\SupplierItemListingResource;
use App\Modules\Purchasing\Services\SupplierListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class SupplierListingController extends Controller
{
    public function __construct(
        private readonly SupplierListingService $service,
    ) {}

    /**
     * GET /api/v1/purchasing/supplier-listings
     * Internal review queue of supplier-submitted item offers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->service->listForReview([
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return SupplierItemListingResource::collection($paginator);
    }

    /**
     * PATCH /api/v1/purchasing/supplier-listings/{listing}/approve
     * Approval is what syncs the offer into approved_suppliers.
     */
    public function approve(ApproveSupplierListingRequest $request, SupplierItemListing $supplierItemListing): SupplierItemListingResource
    {
        $listing = $this->service->approve($supplierItemListing, $request->user());

        return new SupplierItemListingResource($listing);
    }

    /**
     * PATCH /api/v1/purchasing/supplier-listings/bulk-approve
     * Accepts hash IDs; returns a per-row outcome so partial batches surface.
     */
    public function bulkApprove(BulkApproveSupplierListingRequest $request): JsonResponse
    {
        $results = $this->service->bulkApprove(
            $this->decodeIds($request->validated('ids')),
            $request->user(),
        );

        return response()->json(['data' => $results]);
    }

    /**
     * PATCH /api/v1/purchasing/supplier-listings/bulk-reject
     */
    public function bulkReject(BulkRejectSupplierListingRequest $request): JsonResponse
    {
        $results = $this->service->bulkReject(
            $this->decodeIds($request->validated('ids')),
            $request->user(),
            (string) $request->validated('reason'),
        );

        return response()->json(['data' => $results]);
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, int>
     */
    private function decodeIds(array $ids): array
    {
        return array_values(array_filter(array_map(
            fn (string $hash): ?int => HashIdFilter::decode($hash, SupplierItemListing::class),
            $ids,
        )));
    }

    /**
     * PATCH /api/v1/purchasing/supplier-listings/{listing}/reject
     */
    public function reject(RejectSupplierListingRequest $request, SupplierItemListing $supplierItemListing): SupplierItemListingResource
    {
        $listing = $this->service->reject($supplierItemListing, $request->user(), (string) $request->validated('reason'));

        return new SupplierItemListingResource($listing);
    }
}
