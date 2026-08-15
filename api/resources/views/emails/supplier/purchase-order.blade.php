<x-mail::message>
# Approved Purchase Order

Hi {{ $vendor->contact_person ?? $vendor->name ?? 'there' }},

Please review the approved purchase order below and acknowledge it in the supplier portal.

| Detail | Information |
|---|---|
| Purchase order | {{ $purchaseOrder->po_number }} |
| Order date | {{ optional($purchaseOrder->date)->format('M d, Y') }} |
| Expected delivery | {{ optional($purchaseOrder->expected_delivery_date)->format('M d, Y') ?? 'To be confirmed' }} |
| Total amount | ₱{{ number_format((float) $purchaseOrder->total_amount, 2) }} |
| Payment terms | {{ $vendor->payment_terms_days ?? '—' }} days |

## Ordered items

@foreach ($items as $item)
- **{{ $item->item?->name ?? $item->description ?? 'Item' }}** — {{ number_format((float) $item->quantity, 2) }} × ₱{{ number_format((float) $item->unit_price, 2) }} = ₱{{ number_format((float) $item->total, 2) }}
@endforeach

<x-mail::button :url="$portalUrl">Review Purchase Order</x-mail::button>

Please acknowledge the order, provide shipment information, and upload the required shipping documents through the portal.

Regards,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Purchasing Team
</x-mail::message>
