<x-mail::message>
# Credit note {{ $snapshot['credit_note_number'] }} finalized

Hello {{ $snapshot['party_name'] }},

The following credit note has been finalized.

| Detail | Value |
|---|---|
| Credit note | {{ $snapshot['credit_note_number'] }} |
| Type | {{ $snapshot['type_label'] }} |
| Date | {{ $snapshot['date'] }} |
| Subtotal | {{ $snapshot['subtotal'] }} |
| VAT | {{ $snapshot['vat_amount'] }} |
| Total credit | {{ $snapshot['total_amount'] }} |
| Reason | {{ $snapshot['reason'] }} |

The credit is now posted and available for the applicable invoice or bill process.

<x-mail::button :url="$snapshot['portal_url']">Open portal</x-mail::button>

Regards,<br>
Ogami Philippines Finance
</x-mail::message>
