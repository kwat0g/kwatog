<x-mail::message>
# Supplier invoice received

Hello {{ $supplierName }},

{{ config('mail.from.name') }} received your invoice submission for Accounts Payable review.

| Detail | Value |
|---|---|
| Supplier invoice | {{ $invoiceNumber }} |
| Invoice date | {{ $invoiceDate ?? 'Not provided' }} |
| Receipt matches | {{ $matchCount }} |
| Total amount | {{ $grossTotal }} |
@if ($purchaseOrderNumber)
| Purchase order | {{ $purchaseOrderNumber }} |
@endif

This confirmation means the submission was received; payment remains subject to review, matching, and the agreed payment terms.

<x-mail::button :url="$portalUrl">View supplier portal</x-mail::button>

Regards,<br>
{{ config('mail.from.name') }} Accounts Payable
</x-mail::message>
