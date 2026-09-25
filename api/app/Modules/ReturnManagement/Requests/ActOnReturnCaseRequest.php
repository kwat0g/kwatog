<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActOnReturnCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->is('api/v1/b2b/customer/*')) {
            return $this->user('customer_portal') !== null && in_array($this->input('action'), ['reply', 'withdraw', 'reopen', 'resolve_trace'], true);
        }
        if ($this->is('api/v1/b2b/supplier/*')) {
            return $this->user('supplier_portal') !== null && in_array($this->input('action'), ['reply', 'acknowledge', 'reopen'], true);
        }
        $user = $this->user();
        if ($this->input('action') === 'create_replacement') {
            return $user?->hasPermission('return_management.approve') ?? false;
        }
        if ($this->input('action') === 'create_credit') {
            return $user?->hasPermission('accounting.credit_notes.manage') ?? false;
        }
        return ($user?->hasPermission('return_management.manage') ?? false)
            || ($user?->hasPermission('return_management.approve') ?? false);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['reply', 'acknowledge', 'start_review', 'request_info', 'assign', 'agree', 'create_return', 'create_replacement', 'create_credit', 'link_resolution', 'resolve', 'resolve_trace', 'reject', 'withdraw', 'reopen'])],
            'message' => ['nullable', 'string', 'max:2000'],
            'is_public' => ['sometimes', 'boolean'],
            'assigned_to' => ['nullable', 'string', 'max:100'],
            'resolution' => ['nullable', Rule::in(['return_goods', 'redelivery', 'credit', 'no_action'])],
            'expected_date' => ['nullable', 'date', 'after_or_equal:today'],
            'lines' => ['sometimes', 'array', 'max:100'],
            'lines.*.id' => ['required', 'string', 'distinct'],
            'lines.*.verified_missing_quantity' => ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'lines.*.verified_defective_quantity' => ['required', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'credit_note_id' => ['nullable', 'string', 'max:100'],
            'replacement_delivery_id' => ['nullable', 'string', 'max:100'],
            'resolution_goods_receipt_note_id' => ['nullable', 'string', 'max:100'],
            'resolution_goods_receipt_note_ids' => ['sometimes', 'array', 'max:50'],
            'resolution_goods_receipt_note_ids.*' => ['required', 'string', 'max:100', 'distinct'],
            'replacement_sales_order_id' => ['nullable', 'string', 'max:100'],
            'replacement_purchase_order_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
