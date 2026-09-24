<?php

declare(strict_types=1);

namespace App\Modules\Loans\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestLoanWriteOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('loans.write_off.request') ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            // Evidence is a controlled reference (document number, vault path,
            // or case reference), not an unvalidated file upload.
            'evidence' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
