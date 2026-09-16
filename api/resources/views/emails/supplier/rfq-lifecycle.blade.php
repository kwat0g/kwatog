<x-mail::message>
# Supplier RFQ {{ $rfq->rfq_number }}

Hello {{ $invitation->vendor->contact_person ?? $invitation->vendor->name ?? 'supplier' }},

Ogami has an update for **{{ $rfq->title }}**.

| Detail | Information |
|---|---|
| RFQ | {{ $rfq->rfq_number }} |
| Status | {{ $rfq->status?->label() ?? $rfq->status }} |
| Deadline | {{ optional($rfq->closes_at)->format('M d, Y H:i') }} |

Competitor pricing and rankings are never disclosed. Review your invitation in the supplier portal for the requirements, addenda, response status, and outcome.

<x-mail::button :url="$portalUrl">Open supplier RFQ</x-mail::button>

Regards,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Purchasing Team
</x-mail::message>
