<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\TrashedFilter;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentService
{
    public function list(Employee $employee, array $filters): LengthAwarePaginator
    {
        $query = $employee->documents()->getQuery();
        TrashedFilter::apply($query, $filters);
        if (!empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }
        return $query->orderByDesc('uploaded_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function upload(Employee $employee, array $data, ?UploadedFile $file = null): EmployeeDocument
    {
        return DB::transaction(function () use ($employee, $data, $file) {
            if ($file) {
                $path = $file->store('employee-documents/'.$employee->id, 'local');
                $data['file_path'] = $path;
                $data['file_name'] = $file->getClientOriginalName();
            }
            $data['employee_id'] = $employee->id;
            $data['uploaded_at'] = now();

            return EmployeeDocument::create($data);
        });
    }

    public function delete(EmployeeDocument $document): void
    {
        if ($document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }
        $document->delete();
    }

    public function download(EmployeeDocument $document): ?string
    {
        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            return Storage::disk('local')->path($document->file_path);
        }
        return null;
    }
}