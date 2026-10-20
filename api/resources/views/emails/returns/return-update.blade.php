<x-mail::message>
# Return request update

Hello {{ $snapshot['party_contact_name'] ?: $snapshot['party_name'] ?: 'there' }},

Your {{ $snapshot['type_label'] }} **{{ $snapshot['rma_number'] }}** has been updated by Ogami Philippines.

| Detail | Value |
|---|---|
| Status | {{ $snapshot['status_label'] }} |
| Return date | {{ $snapshot['return_date'] ? \Illuminate\Support\Carbon::parse($snapshot['return_date'])->format('F j, Y') : '—' }} |
| Reason | {{ $snapshot['reason_description'] ?: '—' }} |
| Resolution | {{ $snapshot['resolution'] ?: 'Pending review' }} |
@if ($snapshot['refund_amount'])
| Refund / credit amount | {{ number_format((float) $snapshot['refund_amount'], 2) }} |
@endif

We will keep the return record updated as inspection, disposition, credit, replacement, or completion steps progress. Please contact your Ogami customer or supplier operations representative for the current record and any required action.

Regards,<br>
Ogami Philippines Customer and Supplier Operations
</x-mail::message>
