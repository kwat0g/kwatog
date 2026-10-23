<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

Hello {{ $emailSnapshot['customer_contact_name'] ?: $emailSnapshot['customer_name'] }},

Delivery **{{ $emailSnapshot['delivery_number'] }}** for sales order **{{ $emailSnapshot['sales_order_number'] }}** has been recorded as confirmed.

| Delivery | Information |
|---|---:|
| Reference | {{ $emailSnapshot['delivery_number'] }} |
| Sales order | {{ $emailSnapshot['sales_order_number'] }} |
| Status | Confirmed |
@if ($deliveredAtDisplay)
| Delivered | {{ $deliveredAtDisplay }} |
@endif
@if ($emailSnapshot['invoice_number'])
| Invoice reference | {{ $emailSnapshot['invoice_number'] }} |
@endif

<x-mail::button :url="$portalUrl">View delivery</x-mail::button>

Sign in to your customer portal to review the delivery record. The link opens the delivery page and does not confirm or change anything.

You received this email because your customer account is associated with this delivery. If this update looks unexpected, open the customer portal address you normally use or contact the company using the details in the footer.

This is an automated notice from {{ $brand['name'] }}. Use the company contact details in the footer if you need help.
</x-mail::message>
