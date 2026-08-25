<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Support\TrashedFilter;
use App\Common\Support\DepartmentScope;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentService
{
    public function list(Employee $employee, array $filters, User $actor): LengthAwarePaginator
    {
        $this->assertEmployeeVisible($employee, $actor);
        $query = $employee->documents()->getQuery();
        TrashedFilter::apply($query, $filters);
        if (!empty($filters['document_type'])) {
            $query->where('document_type', $filters['document_type']);
        }
        return $query->orderByDesc('uploaded_at')->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function upload(Employee $employee, array $data, ?UploadedFile $file, User $actor): EmployeeDocument
    {
        $employee = $this->assertEmployeeVisible($employee, $actor);

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

    public function delete(Employee $employee, EmployeeDocument $document, User $actor): void
    {
        $this->assertDocumentBelongsToEmployee($employee, $document);
        $this->assertEmployeeVisible($employee, $actor);
        if ($document->file_path) {
            Storage::disk('local')->delete($document->file_path);
        }
        $document->delete();
    }

    public function restore(Employee $employee, EmployeeDocument $document, User $actor): void
    {
        $this->assertDocumentBelongsToEmployee($employee, $document);
        $this->assertEmployeeVisible($employee, $actor);
        $document->restore();
    }

    public function download(EmployeeDocument $document, User $actor): ?string
    {
        $employee = $document->employee;
        if (! $employee) {
            abort(404);
        }
        $this->assertEmployeeVisible($employee, $actor);

        if ($document->file_path && Storage::disk('local')->exists($document->file_path)) {
            return Storage::disk('local')->path($document->file_path);
        }
        return null;
    }

    private function assertEmployeeVisible(Employee $employee, User $actor): Employee
    {
        $query = Employee::query()->whereKey($employee->getKey());
        DepartmentScope::apply(
            $query,
            $actor,
            viewAllPermission: 'hr.employees.view_sensitive',
            departmentPermission: 'hr.employees.view',
            deptColumn: 'department_id',
            selfColumn: 'id',
            selfId: $actor->employee_id,
        );

        return $query->firstOrFail();
    }

    private function assertDocumentBelongsToEmployee(Employee $employee, EmployeeDocument $document): void
    {
        abort_unless((int) $document->employee_id === (int) $employee->getKey(), 404);
    }
}
