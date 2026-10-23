<x-mail::message :preheader="$preheader" title="Customer complaint update">
# Customer complaint update

Hello {{ $complaint['recipient_name'] }},

We recorded an update to complaint **{{ $complaint['complaint_number'] }}** for {{ $brand['name'] }}.

| Detail | Value |
|---|---|
| Status | {{ $complaint['status_label'] }} |
@if ($complaint['received_date'])
| Received | {{ $complaint['received_date'] }} |
@endif
@if ($complaint['product_label'])
| Product | {{ $complaint['product_label'] }} |
@endif

Complaint details are available in the customer portal.

<x-mail::button :url="$portalUrl">View your complaints</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Customer Support

You received this email because your customer account is associated with this complaint. To verify the request, open the customer portal using the address you normally use.
</x-mail::message>
