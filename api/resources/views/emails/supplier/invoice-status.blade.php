<x-mail::message :preheader="$preheader" title="Supplier invoice received">
# Supplier invoice received

Hello {{ $supplierName }},

{{ $brand['name'] }} received your invoice submission. Accounts Payable will review it against the related records and agreed payment terms.

| Detail | Value |
|---|---|
| Invoice reference | {{ $invoiceNumber }} |
@if ($invoiceDate)
| Invoice date | {{ $invoiceDate }} |
@endif
| Bills included | {{ $matchCount }} |
| Submitted total | {{ $grossTotal }} |
@if ($purchaseOrderNumber)
| Purchase order | {{ $purchaseOrderNumber }} |
@endif

<x-mail::panel>
This confirms receipt only. It is not approval for payment. The submitted amount and reference shown here are from the time of submission.
</x-mail::panel>

<x-mail::button :url="$portalUrl">View submitted invoice</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Accounts Payable

You received this email because this supplier invoice was submitted through the supplier portal. To verify the request, open the supplier portal using the address you normally use.
</x-mail::message>
