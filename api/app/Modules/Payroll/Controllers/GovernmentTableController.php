<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use App\Modules\Payroll\Requests\UpdateGovTableBracketRequest;
use App\Modules\Payroll\Resources\GovernmentTableResource;
use App\Modules\Payroll\Services\GovernmentContributionTableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GovernmentTableController
{
    public function __construct(private readonly GovernmentContributionTableService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'agency' => ['required', Rule::in(ContributionAgency::values())],
        ]);

        $rows = $this->service->list($request->string('agency')->value());
        return GovernmentTableResource::collection($rows);
    }

    public function update(UpdateGovTableBracketRequest $request, GovernmentContributionTable $govTable): GovernmentTableResource
    {
        return new GovernmentTableResource($this->service->update($govTable, $request->validated()));
    }

    public function deactivate(GovernmentContributionTable $govTable): GovernmentTableResource
    {
        return new GovernmentTableResource($this->service->deactivate($govTable));
    }

    public function activate(GovernmentContributionTable $govTable): GovernmentTableResource
    {
        return new GovernmentTableResource($this->service->activate($govTable));
    }

    public function destroy(GovernmentContributionTable $govTable): JsonResponse
    {
        // Hard delete only allowed for unused rows (defensive — check audit log usage in service later if needed).
        $govTable->delete();
        return response()->json(null, 204);
    }
}
