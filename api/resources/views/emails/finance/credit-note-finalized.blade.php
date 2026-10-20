<x-mail::message>
# {{ $snapshot['type_label'] }} note {{ $snapshot['credit_note_number'] }} finalized

Hello {{ $snapshot['party_name'] }},

@if ($snapshot['type'] === 'customer')
A customer credit note has been finalized for your account.
@else
A supplier credit note has been finalized for your account.
@endif

| Credit note detail | Value |
|:--|--:|
| Status | Finalized |
| Credit note | {{ $snapshot['credit_note_number'] }} |
| Date | {{ $snapshot['date'] }} |
| Subtotal | {{ $snapshot['subtotal'] }} |
| VAT | {{ $snapshot['vat_amount'] }} |
| Total credit | {{ $snapshot['total_amount'] }} |
| Applied at finalization | {{ $snapshot['applied_amount'] }} |
| Remaining credit at finalization | {{ $snapshot['balance'] }} |
@if ($snapshot['type'] === 'customer')
| Related invoice | {{ $snapshot['invoice_number'] }} |
@else
| Related bill | {{ $snapshot['bill_number'] }} |
@endif

This summary reflects the credit note when it was finalized. Later applications or voids may not appear here. To request a copy or ask about how the credit is applied, contact the company using the details below and include the credit note number.

@if ($snapshot['type'] === 'customer')
<x-mail::button :url="$snapshot['portal_url']">Open customer portal</x-mail::button>
@else
<x-mail::button :url="$snapshot['portal_url']">Open supplier portal</x-mail::button>
@endif

Regards,<br>
@if ($snapshot['type'] === 'customer')
Accounts Receivable
@else
Accounts Payable
@endif

You received this email because a credit note was finalized for your account.
</x-mail::message>
