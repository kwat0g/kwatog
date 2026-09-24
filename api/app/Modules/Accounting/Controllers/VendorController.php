<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Requests\Bir2307Request;
use App\Modules\Accounting\Requests\StoreVendorRequest;
use App\Modules\Accounting\Requests\UpdateVendorRequest;
use App\Modules\Accounting\Resources\VendorResource;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Accounting\Services\Bir2307Service;
use App\Modules\Accounting\Services\VendorService;
use App\Modules\Accounting\Services\PdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class VendorController
{
    public function __construct(
        private readonly VendorService $service,
        private readonly BillService $bills,
        private readonly Bir2307Service $bir2307,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return VendorResource::collection($this->service->list($request->query()));
    }

    public function show(Vendor $vendor): VendorResource
    {
        $vendor = $this->service->show($vendor);
        $vendor->setAttribute('open_balance', $this->bills->openBalance($vendor));
        return new VendorResource($vendor);
    }

    /** BIR 2307 — EWT withheld from this vendor's posted payments in a quarter. */
    public function bir2307(Bir2307Request $request, Vendor $vendor): JsonResponse
    {
        return response()->json(['data' => $this->bir2307->forVendorQuarter(
            $vendor,
            (int) $request->validated('year'),
            (int) $request->validated('quarter'),
        )]);
    }

    /** BIR 2307 PDF — server-rendered certificate. */
    public function bir2307Pdf(Bir2307Request $request, Vendor $vendor, PdfService $pdf): Response
    {
        return $pdf->bir2307(
            $vendor,
            (int) $request->validated('year'),
            (int) $request->validated('quarter'),
        );
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = $this->service->create($request->validated());
        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): VendorResource
    {
        $vendor = $this->service->update($vendor, $request->validated());
        return new VendorResource($vendor);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        try {
            $this->service->delete($vendor);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(null, 204);
    }

    public function restore(Vendor $vendor): JsonResponse
    {
        try {
            $this->service->restore($vendor);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return response()->json(['message' => 'Vendor restored.']);
    }
}
