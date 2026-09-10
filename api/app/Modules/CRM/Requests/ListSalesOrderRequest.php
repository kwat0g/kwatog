<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSalesOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.sales_orders.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            // A single status string or an array of statuses
            // (status[]=confirmed&status[]=in_production&…). Values are
            // validated in SalesOrderService::list(), which throws a
            // ValidationException for anything outside SalesOrderStatus —
            // same pattern as WorkOrderService::list().
            'status'      => ['sometimes', 'nullable'],
            'date_from'   => ['sometimes', 'nullable', 'date'],
            'date_to'     => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'search'      => ['sometimes', 'nullable', 'string', 'max:120'],
            'trashed'     => ['sometimes', Rule::in(['with', 'only'])],
            'page'        => ['sometimes', 'integer', 'min:1'],
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
