<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionDefectEvidence;
use App\Modules\Quality\Resources\InspectionDefectEvidenceResource;
use App\Modules\Quality\Services\InspectionDefectEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InspectionDefectEvidenceController
{
    public function __construct(private readonly InspectionDefectEvidenceService $service) {}

    public function index(Inspection $inspection): AnonymousResourceCollection
    {
        return InspectionDefectEvidenceResource::collection($this->service->list($inspection));
    }

    public function store(Request $request, Inspection $inspection): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(['surface_damage', 'contamination', 'dimensional', 'material', 'packaging', 'other'])],
            'other_category' => ['required_if:category,other', 'nullable', 'string', 'max:100'],
            'severity' => ['required', Rule::in(['minor', 'major', 'critical'])],
            'affected_quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:10240'],
        ]);

        $photo = $request->file('photo');
        if (! $photo) {
            throw ValidationException::withMessages(['photo' => ['Attach a photo of the defect.']]);
        }

        $evidence = $this->service->create($inspection, $data, $photo, $request->user());

        return (new InspectionDefectEvidenceResource($evidence))->response()->setStatusCode(201);
    }

    public function photo(InspectionDefectEvidence $evidence): StreamedResponse
    {
        return $this->service->photo($evidence);
    }
}
