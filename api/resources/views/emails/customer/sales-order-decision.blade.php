<x-mail::message>
# Sales Order {{ $salesOrder->so_number }}

Hi {{ $salesOrder->customer->contact_person ?? $salesOrder->customer->name ?? 'there' }},

Our sales team has {{ $decision === 'accept' ? '**accepted**' : '**returned for revision**' }} your response to sales order **{{ $salesOrder->so_number }}**.

| Detail | Information |
|---|---|
| Sales order | {{ $salesOrder->so_number }} |
| Your response | {{ $response->response_type?->label() ?? $response->response_type }} |
| Order total | ₱{{ number_format((float) $salesOrder->total_amount, 2) }} |
@if ($response->proposed_delivery_date)
| Requested delivery | {{ $response->proposed_delivery_date->format('M d, Y') }} |
@endif

@if ($decision === 'accept' && $response->response_type?->value === 'propose')
## Accepted counter-offer

Your proposed quantities and prices were applied to the order. We will confirm the order and keep you updated on production and delivery.
@elseif ($decision === 'reject')
## Response returned

Your response was returned for revision. Please review the sales order and submit a new response through the customer portal.
@endif

<x-mail::button :url="$portalUrl">Review Sales Order</x-mail::button>

Regards,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Sales Team
</x-mail::message>
