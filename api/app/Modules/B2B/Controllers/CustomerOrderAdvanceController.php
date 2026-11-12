<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Requests\SubmitSalesOrderAdvanceRequest;
use App\Modules\CRM\Resources\SalesOrderAdvanceResource;
use App\Modules\CRM\Services\SalesOrderAdvanceService;

class CustomerOrderAdvanceController
{
    public function __construct(private readonly SalesOrderAdvanceService $service) {}

    public function submit(SubmitSalesOrderAdvanceRequest $request, SalesOrder $salesOrder): SalesOrderAdvanceResource
    {
        $portalUser = $request->user();
        abort_unless($portalUser instanceof CustomerPortalUser, 403);
        $data = $request->validated();
        $advance = $this->service->submitEvidence($salesOrder, $data, $request->file('proof_file'), $portalUser);

        return new SalesOrderAdvanceResource($advance->load(['salesOrder', 'customer']));
    }
}
