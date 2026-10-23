<x-mail::message>
# {{ $snapshot['rfq_number'] ?? $rfq->rfq_number }} · {{ $snapshot['title'] ?? $rfq->title }}

Hello {{ $snapshot['contact_person'] ?? $snapshot['vendor_name'] ?? $invitation->vendor->contact_person ?? $invitation->vendor->name ?? 'supplier' }},

@switch($kind)
@case('published')
Ogami invites you to quote for **{{ $snapshot['title'] ?? $rfq->title }}**. Please submit your quotation in the supplier portal before
**{{ isset($snapshot['closes_at']) ? \Illuminate\Support\Carbon::parse($snapshot['closes_at'])->format('M d, Y g:i A') : optional($rfq->closes_at)->format('M d, Y g:i A') }}**, with your quotation PDF and, for materials, the certificate of analysis.
@break
@case('extended')
The quotation deadline for **{{ $snapshot['title'] ?? $rfq->title }}** is now **{{ isset($snapshot['closes_at']) ? \Illuminate\Support\Carbon::parse($snapshot['closes_at'])->format('M d, Y g:i A') : optional($rfq->closes_at)->format('M d, Y g:i A') }}**.
@if ($snapshot['last_extension_reason'] ?? $rfq->last_extension_reason)

Reason: {{ $snapshot['last_extension_reason'] ?? $rfq->last_extension_reason }}
@endif
@break
@case('awarded')
@if (($snapshot['status'] ?? $invitation->status?->value ?? $invitation->status) === 'awarded')
Your quotation was selected for one or more lines. Ogami's purchasing team will send the purchase order after internal approval.
@else
Thank you for quoting. Your quotation was not selected this time.
@endif
@break
@case('cancelled')
This request for quotation has been cancelled. No award will be made.
@if ($snapshot['cancellation_reason'] ?? $rfq->cancellation_reason)

Reason: {{ $snapshot['cancellation_reason'] ?? $rfq->cancellation_reason }}
@endif
@break
@default
There is an update to this request for quotation.
@endswitch

Competitor pricing and rankings are never disclosed.

<x-mail::button :url="$portalUrl">Open in supplier portal</x-mail::button>

Regards,<br>
{{ config('mail.from.name') }} Purchasing Team
</x-mail::message>
