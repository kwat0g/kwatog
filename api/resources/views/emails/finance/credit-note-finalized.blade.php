<x-mail::message>
# Credit note finalized

Hello {{ $party?->contact_person ?: $party?->name ?: 'there' }},

Ogami Philippines has finalized the following credit note.

| Detail | Value |
|---|---|
| Credit note | {{ $creditNote->credit_note_number }} |
| Type | {{ $creditNote->type?->label() ?? $creditNote->type }} |
| Date | {{ optional($creditNote->date)->format('F j, Y') }} |
| Subtotal | {{ number_format((float) $creditNote->subtotal, 2) }} |
| VAT | {{ number_format((float) $creditNote->vat_amount, 2) }} |
| Total credit | {{ number_format((float) $creditNote->total_amount, 2) }} |
| Reason | {{ $creditNote->reason ?: '—' }} |

The credit is now posted and available for the applicable invoice or bill process.

<x-mail::button :url="$portalUrl">Open portal</x-mail::button>

Regards,<br>
Ogami Philippines Finance
</x-mail::message>
