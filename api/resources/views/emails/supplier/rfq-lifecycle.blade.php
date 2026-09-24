<x-mail::message>
# {{ $rfq->rfq_number }} · {{ $rfq->title }}

Hello {{ $invitation->vendor->contact_person ?? $invitation->vendor->name ?? 'supplier' }},

@switch($kind)
@case('published')
Ogami invites you to quote for **{{ $rfq->title }}**. Please submit your quotation in the supplier portal before
**{{ optional($rfq->closes_at)->format('M d, Y H:i') }}**, with your quotation PDF and, for materials, the certificate of analysis.
@break
@case('extended')
The quotation deadline for **{{ $rfq->title }}** is now **{{ optional($rfq->closes_at)->format('M d, Y H:i') }}**.
@if ($rfq->last_extension_reason)

Reason: {{ $rfq->last_extension_reason }}
@endif
@break
@case('awarded')
@if (($invitation->status?->value ?? $invitation->status) === 'awarded')
Your quotation was selected for one or more lines. Ogami's purchasing team will send the purchase order after internal approval.
@else
Thank you for quoting. Your quotation was not selected this time.
@endif
@break
@case('cancelled')
This request for quotation has been cancelled. No award will be made.
@break
@default
There is an update to this request for quotation.
@endswitch

Competitor pricing and rankings are never disclosed.

<x-mail::button :url="$portalUrl">Open in supplier portal</x-mail::button>

Regards,<br>
{{ config('mail.from.name', 'Ogami Philippines') }} Purchasing Team
</x-mail::message>
