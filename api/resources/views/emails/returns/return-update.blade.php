<x-mail::message>
# Return request update

Hello {{ $party?->contact_person ?: $party?->name ?: 'there' }},

Your {{ $returnRequest->type?->label() ?? 'return request' }} **{{ $returnRequest->rma_number }}** has been updated by Ogami Philippines.

| Detail | Value |
|---|---|
| Status | {{ $returnRequest->status?->label() ?? $returnRequest->status }} |
| Return date | {{ optional($returnRequest->return_date)->format('F j, Y') ?: '—' }} |
| Reason | {{ $returnRequest->reason_description ?: '—' }} |
| Resolution | {{ $returnRequest->resolution ?: 'Pending review' }} |
@if ($returnRequest->refund_amount)
| Refund / credit amount | {{ number_format((float) $returnRequest->refund_amount, 2) }} |
@endif

We will keep the return record updated as inspection, disposition, credit, replacement, or completion steps progress.

<x-mail::button :url="$portalUrl">Open portal</x-mail::button>

Regards,<br>
Ogami Philippines Customer and Supplier Operations
</x-mail::message>
