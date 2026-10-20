<x-mail::message>
# Delivery Confirmed

Hi {{ $emailSnapshot['customer_contact_name'] ?: $emailSnapshot['customer_name'] ?: 'there' }},

Delivery **{{ $emailSnapshot['delivery_number'] }}** for sales order **{{ $emailSnapshot['sales_order_number'] ?: '—' }}** has been confirmed in our system.

| Detail | Information |
|---|---|
| Delivery number | {{ $emailSnapshot['delivery_number'] }} |
| Delivered at | {{ $emailSnapshot['delivered_at'] ? \Illuminate\Support\Carbon::parse($emailSnapshot['delivered_at'])->format('M d, Y h:i A') : '—' }} |
| Received by | {{ $emailSnapshot['receiver_name'] ?: '—' }} |
| Receiver position | {{ $emailSnapshot['receiver_position'] ?: '—' }} |
| Invoice | {{ $emailSnapshot['invoice_number'] ?: 'Pending invoice processing' }} |

@if ($emailSnapshot['delivery_remarks'])
**Delivery remarks:** {{ $emailSnapshot['delivery_remarks'] }}
@endif

<x-mail::button :url="$portalUrl">View Delivery in Customer Portal</x-mail::button>

Thank you,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Supply Chain Team
</x-mail::message>
