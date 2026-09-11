<x-mail::message>
# Purchase Order {{ $purchaseOrder->po_number }}

Hi {{ $purchaseOrder->vendor->contact_person ?? $purchaseOrder->vendor->name ?? 'there' }},

Purchasing has {{ $decision === 'accept' ? '**accepted**' : '**returned for revision**' }} your response to purchase order **{{ $purchaseOrder->po_number }}**.

| Detail | Information |
|---|---|
| Purchase order | {{ $purchaseOrder->po_number }} |
| Your response | {{ $response->response_type?->label() ?? $response->response_type }} |
| Order total | ₱{{ number_format((float) $purchaseOrder->total_amount, 2) }} |
@if ($response->proposed_delivery_date)
| Agreed delivery | {{ $response->proposed_delivery_date->format('M d, Y') }} |
@endif

@if ($decision === 'accept' && $response->response_type?->value === 'propose')
## Accepted counter-offer

Your proposed quantities and prices were applied to the order. Please confirm the updated line items in the supplier portal and proceed with shipment.
@elseif ($decision === 'reject')
## Response returned

Your response was returned for revision. Please review the purchase order and submit a new response through the supplier portal.
@endif

<x-mail::button :url="$portalUrl">Review Purchase Order</x-mail::button>

Regards,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Purchasing Team
</x-mail::message>
