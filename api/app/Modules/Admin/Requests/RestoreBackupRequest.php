<?php

declare(strict_types=1);

namespace App\Modules\Admin\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RestoreBackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('admin.backups.manage') ?? false;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'database_filename' => ['required', 'string', 'max:180'],
            'files_filename' => ['nullable', 'string', 'max:180'],
            'confirmation' => ['required', 'string', 'max:220'],
        ];
    }
}
