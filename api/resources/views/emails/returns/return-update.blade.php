<x-mail::message :preheader="$preheader" :title="$title">
# {{ $notice['title'] }}

Hello {{ $emailSnapshot['party_contact_name'] ?: $emailSnapshot['party_name'] ?: 'there' }},

{{ $notice['message'] }}

| Return request | Information |
|---|---:|
| Reference | {{ $emailSnapshot['rma_number'] }} |
| Status | {{ $emailSnapshot['status_label'] }} |

<x-mail::button :url="$portalUrl">{{ $portalButtonLabel }}</x-mail::button>

This message includes only the request reference and current status. {{ $portalInstruction }}

You received this email because your contact address is listed on the {{ $portalLabel }} record for this return request. Verify the update by opening the portal address you normally use.

This is an automated notice. Use the company contact details in the footer if you need help.
</x-mail::message>
