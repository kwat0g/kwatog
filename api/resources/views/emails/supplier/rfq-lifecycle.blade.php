<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

Hello {{ $notice['recipient_name'] }},

{{ $notice['message'] }}

| Request detail | Information |
|---|---:|
| Request for quotation | {{ $notice['rfq_number'] }} |
| Request title | {{ $notice['rfq_title'] }} |
| Status | {{ $notice['status'] }} |
@if (in_array($kind, ['published', 'extended'], true) && $notice['deadline'])
| Submission deadline | {{ $notice['deadline'] }} |
@endif

**What to do:** {{ $notice['action'] }}

<x-mail::button :url="$portalUrl">Open supplier portal</x-mail::button>

This message contains only your request status. Other suppliers' quotations and rankings are not included.

You received this email because this address is listed for a supplier invited to this request for quotation. To verify it, open the supplier portal at the address you normally use and confirm the request is listed there.

This is an automated notice. For help, use the company contact details in the footer.

Regards,<br>
{{ $brand['name'] }} Purchasing Team
</x-mail::message>
