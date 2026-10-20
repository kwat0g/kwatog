<x-mail::message :preheader="$preheader" title="Customer complaint update">
# Customer complaint update

Hello {{ $complaint['recipient_name'] }},

We recorded an update to complaint **{{ $complaint['complaint_number'] }}** with {{ $brand['name'] }}. Its current status is:

<x-mail::panel>
<strong>{{ $complaint['status_label'] }}</strong>
</x-mail::panel>

| Detail | Value |
|---|---|
| Complaint | {{ $complaint['complaint_number'] }} |
@if ($complaint['received_date'])
| Received | {{ $complaint['received_date'] }} |
@endif
@if ($complaint['product_label'])
| Product | {{ $complaint['product_label'] }} |
@endif

Open the customer portal to view the complaint record and its available updates.

<x-mail::button :url="$portalUrl">View your complaints</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Customer Support

You received this email because your customer account is associated with this complaint. To verify the request, open the customer portal using the address you normally use.
</x-mail::message>
