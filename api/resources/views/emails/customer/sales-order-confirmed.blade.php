<x-mail::message>
# Sales Order Confirmed

Hi {{ $customer->contact_person ?? $customer->name ?? 'there' }},

Your sales order **{{ $salesOrder->so_number }}** has been confirmed and is now being processed.

| Detail | Information |
|---|---|
| Order number | {{ $salesOrder->so_number }} |
| Order date | {{ optional($salesOrder->date)->format('M d, Y') }} |
| Total amount | ₱{{ number_format((float) $salesOrder->total_amount, 2) }} |
| Payment terms | {{ $salesOrder->payment_terms_days ?? '—' }} days |
| Delivery terms | {{ $salesOrder->delivery_terms ?? '—' }} |

## Items

@foreach ($items as $item)
- **{{ $item->product?->name ?? 'Item' }}** — {{ number_format((float) $item->quantity, 2) }} × ₱{{ number_format((float) $item->unit_price, 2) }} = ₱{{ number_format((float) $item->total, 2) }}
@endforeach

<x-mail::button :url="$portalUrl">View Order in Customer Portal</x-mail::button>

We will provide further updates as production and delivery progress.

Thanks,<br>
{{ config('mail.from.name', 'Ogami Philippines') }}
</x-mail::message>
