<x-mail::message :preheader="'Invoice '.$invoiceData->invoice_number.' has been issued.'" :title="'Invoice '.$invoiceData->invoice_number.' issued'">
# Invoice issued

Hi {{ $customer->contact_person ?? $customer->name ?? 'there' }},

Invoice **{{ $invoiceData->invoice_number }}** has been issued. Please review the balance and due date below.

| Invoice summary | Details |
|---|---|
| Invoice number | **{{ $invoiceData->invoice_number }}** |
| Invoice date | {{ optional($invoiceData->date)->format('M d, Y') }} |
| Due date | **{{ optional($invoiceData->due_date)->format('M d, Y') }}** |
| Total amount | ₱{{ number_format((float) $invoiceData->total_amount, 2) }} |
| Balance due | **₱{{ number_format((float) $invoiceData->balance, 2) }}** |
| Sales order | {{ $salesOrder?->so_number ?? '—' }} |

## Line items

@foreach ($items as $item)
- **{{ $item->description }}**<br>{{ number_format((float) $item->quantity, 2) }} × ₱{{ number_format((float) $item->unit_price, 2) }} = **₱{{ number_format((float) $item->total, 2) }}**
@endforeach

<x-mail::button :url="$portalUrl">View Invoice in Customer Portal</x-mail::button>

If payment has already been made, please reply with proof of payment so our Accounts Receivable team can update the record.

Thanks,<br>
{{ config('mail.from.name', config('app.name')) }} Accounts Receivable
</x-mail::message>
