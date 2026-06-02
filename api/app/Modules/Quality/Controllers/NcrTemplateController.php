<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Modules\Quality\Models\NcrTemplate;
use App\Modules\Quality\Resources\NcrTemplateResource;
use App\Modules\Quality\Services\NcrTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * ADV7 — NCR template CRUD.
 *
 * Templates let QC officers file common NCR types in one click
 * by pre-filling source, severity, product, and defect description.
 */
class NcrTemplateController
{
    public function __construct(private readonly NcrTemplateService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return NcrTemplateResource::collection($this->service->list($request->query()));
    }

    public function show(NcrTemplate $ncrTemplate): NcrTemplateResource
    {
        return new NcrTemplateResource($this->service->show($ncrTemplate));
    }

    public function store(Request $request): NcrTemplateResource
    {
        $data = $request->validate([
            'name'               => ['required', 'string', 'max:200'],
            'source'             => ['required', Rule::in(['inspection_fail', 'customer_complaint'])],
            'severity'           => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'product_id'         => ['nullable', 'string'],
            'defect_description' => ['nullable', 'string', 'max:5000'],
            'notes'              => ['nullable', 'string', 'max:2000'],
        ]);
        return new NcrTemplateResource($this->service->create($data, $request->user()));
    }

    public function update(Request $request, NcrTemplate $ncrTemplate): NcrTemplateResource
    {
        $data = $request->validate([
            'name'               => ['sometimes', 'string', 'max:200'],
            'source'             => ['sometimes', Rule::in(['inspection_fail', 'customer_complaint'])],
            'severity'           => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'product_id'         => ['nullable', 'string'],
            'defect_description' => ['nullable', 'string', 'max:5000'],
            'notes'              => ['nullable', 'string', 'max:2000'],
            'is_active'          => ['sometimes', 'boolean'],
        ]);
        return new NcrTemplateResource($this->service->update($ncrTemplate, $data));
    }

    public function destroy(NcrTemplate $ncrTemplate): NcrTemplateResource
    {
        return new NcrTemplateResource($this->service->deactivate($ncrTemplate));
    }

    /**
     * GET /api/v1/quality/ncr-templates/active
     * Returns only active templates (for the "Use template" dropdown).
     */
    public function active(): AnonymousResourceCollection
    {
        return NcrTemplateResource::collection($this->service->list(['is_active' => true]));
    }
}
