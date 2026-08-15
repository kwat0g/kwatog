<x-mail::message>
# Delivery Confirmed

Hi {{ $customer->contact_person ?? $customer->name ?? 'there' }},

Delivery **{{ $delivery->delivery_number }}** for sales order **{{ $salesOrder?->so_number ?? '—' }}** has been confirmed in our system.

| Detail | Information |
|---|---|
| Delivery number | {{ $delivery->delivery_number }} |
| Delivered at | {{ optional($delivery->delivered_at)->format('M d, Y h:i A') ?? '—' }} |
| Received by | {{ $delivery->receiver_name ?? '—' }} |
| Receiver position | {{ $delivery->receiver_position ?? '—' }} |
| Invoice | {{ $invoiceNumber ?? 'Pending invoice processing' }} |

@if ($delivery->delivery_remarks)
**Delivery remarks:** {{ $delivery->delivery_remarks }}
@endif

<x-mail::button :url="$portalUrl">View Delivery in Customer Portal</x-mail::button>

Thank you,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Supply Chain Team
</x-mail::message>
