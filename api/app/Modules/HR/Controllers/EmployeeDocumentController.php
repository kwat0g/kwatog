<?php

declare(strict_types=1);

namespace App\Modules\HR\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Requests\StoreEmployeeDocumentRequest;
use App\Modules\HR\Resources\EmployeeDocumentResource;
use App\Modules\HR\Services\EmployeeDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Str;

class EmployeeDocumentController
{
    public function __construct(
        private readonly EmployeeDocumentService $service,
    ) {}

    public function index(Request $request, Employee $employee): AnonymousResourceCollection
    {
        return EmployeeDocumentResource::collection(
            $this->service->list($employee, $request->query(), $request->user()),
        );
    }

    /** GET /hr/employees/{employee}/documents/options */
    public function options(): JsonResponse
    {
        // Employee document types are intentionally extensible. Return the
        // values already used by the organization instead of duplicating a
        // fixed list in every client.
        $types = EmployeeDocument::query()
            ->whereNotNull('document_type')
            ->where('document_type', '<>', '')
            ->distinct()
            ->orderBy('document_type')
            ->pluck('document_type')
            ->map(static fn (string $value): array => [
                'value' => $value,
                'label' => Str::headline($value),
            ])
            ->values();

        return response()->json(['data' => ['document_types' => $types]]);
    }

    public function store(StoreEmployeeDocumentRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->safe()->except('file');
        $document = $this->service->upload($employee, $data, $request->file('file'), $request->user());
        return (new EmployeeDocumentResource($document))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Employee $employee, EmployeeDocument $employeeDocument): JsonResponse
    {
        $this->service->delete($employee, $employeeDocument, $request->user());
        return response()->json(null, 204);
    }

    public function restore(Request $request, Employee $employee, EmployeeDocument $employeeDocument): JsonResponse
    {
        $this->service->restore($employee, $employeeDocument, $request->user());
        return response()->json(['message' => 'Employee document restored.']);
    }

    public function download(Request $request, EmployeeDocument $employeeDocument): JsonResponse|BinaryFileResponse
    {
        $path = $this->service->download($employeeDocument, $request->user());
        if (! $path) {
            return response()->json(['message' => 'File not found.'], 404);
        }
        return response()->file($path, ['Content-Disposition' => 'attachment; filename="'.$employeeDocument->file_name.'"']);
    }
}
