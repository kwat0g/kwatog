<x-mail::message>
# Supplier invoice received

Hello {{ $bill->vendor?->contact_person ?: $bill->vendor?->name ?: 'Supplier' }},

Ogami Philippines received your invoice submission and created a draft bill for Accounts Payable review.

| Detail | Value |
|---|---|
| Bill reference | {{ $bill->bill_number }} |
| Invoice date | {{ optional($bill->date)->format('F j, Y') }} |
| Due date | {{ optional($bill->due_date)->format('F j, Y') ?: '—' }} |
| Total amount | {{ number_format((float) $bill->total_amount, 2) }} |
@if ($bill->purchaseOrder)
| Purchase order | {{ $bill->purchaseOrder->po_number }} |
@endif

This confirmation means the submission was received; payment remains subject to review, matching, and the agreed payment terms.

<x-mail::button :url="$portalUrl">View supplier portal</x-mail::button>

Regards,<br>
Ogami Philippines Accounts Payable
</x-mail::message>
