<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\HR\Models\Department;
use App\Modules\Purchasing\Models\PurchaseRequestTemplate;
use App\Modules\Purchasing\Resources\PurchaseRequestTemplateResource;
use App\Modules\Purchasing\Services\PurchaseRequestTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseRequestTemplateController
{
    public function __construct(
        private readonly PurchaseRequestTemplateService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PurchaseRequestTemplateResource::collection(
            $this->service->list($request->query())
        );
    }

    public function show(PurchaseRequestTemplate $template): PurchaseRequestTemplateResource
    {
        return new PurchaseRequestTemplateResource($template->load(['department:id,name,code', 'creator:id,name']));
    }

    public function store(Request $request): JsonResponse
    {
        $this->decodeDepartmentId($request);
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'department_id' => 'nullable|exists:departments,id',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'nullable',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.estimated_unit_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $template = $this->service->create($validated, $request->user());

        return response()->json(['data' => $this->service->show($template)], 201);
    }

    public function update(Request $request, PurchaseRequestTemplate $template): array
    {
        $this->decodeDepartmentId($request);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'department_id' => 'nullable|exists:departments,id',
            'items' => 'sometimes|array|min:1',
            'items.*.item_id' => 'nullable',
            'items.*.description' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|numeric|min:0',
            'items.*.unit' => 'nullable|string|max:50',
            'items.*.estimated_unit_price' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $template = $this->service->update($template, $validated);

        return ['data' => $this->service->show($template)];
    }

    /**
     * Return all active templates (simplified, no pagination) for the
     * "Use Template" picker in the PR create page.
     */
    public function active(Request $request): Collection
    {
        return collect($this->service->allActive());
    }

    public function destroy(PurchaseRequestTemplate $template): JsonResponse
    {
        try {
            $this->service->delete($template);
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function restore(PurchaseRequestTemplate $template): JsonResponse
    {
        $template->restore();
        return response()->json(['message' => 'Purchase request template restored.']);
    }

    private function decodeDepartmentId(Request $request): void
    {
        if (! $request->filled('department_id')) {
            return;
        }

        $id = HashIdFilter::decode($request->input('department_id'), Department::class);
        if ($id === null) {
            throw ValidationException::withMessages([
                'department_id' => ['The selected department is invalid.'],
            ]);
        }

        $request->merge(['department_id' => $id]);
    }
}
