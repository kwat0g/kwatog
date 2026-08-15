<x-mail::message>
# Invoice Issued

Hi {{ $customer->contact_person ?? $customer->name ?? 'there' }},

Invoice **{{ $invoice->invoice_number }}** has been issued for your account.

| Detail | Information |
|---|---|
| Invoice number | {{ $invoice->invoice_number }} |
| Invoice date | {{ optional($invoice->date)->format('M d, Y') }} |
| Due date | {{ optional($invoice->due_date)->format('M d, Y') }} |
| Total amount | ₱{{ number_format((float) $invoice->total_amount, 2) }} |
| Balance due | ₱{{ number_format((float) $invoice->balance, 2) }} |
| Sales order | {{ $salesOrder?->so_number ?? '—' }} |

## Line items

@foreach ($items as $item)
- **{{ $item->description }}** — {{ number_format((float) $item->quantity, 2) }} × ₱{{ number_format((float) $item->unit_price, 2) }} = ₱{{ number_format((float) $item->total, 2) }}
@endforeach

<x-mail::button :url="$portalUrl">View Invoice in Customer Portal</x-mail::button>

If payment has already been made, please reply with proof of payment so our Accounts Receivable team can update the record.

Thanks,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Accounts Receivable
</x-mail::message>
