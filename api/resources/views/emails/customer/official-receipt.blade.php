<x-mail::message>
# Official receipt {{ $snapshot['or_number'] }} issued

Hello {{ $snapshot['customer_name'] }},

Your payment has been recorded and an official receipt has been issued.

| Receipt detail | Value |
|:--|--:|
| Status | Issued |
| Receipt number | {{ $snapshot['or_number'] }} |
| Receipt date | {{ $snapshot['date'] }} |
| Amount received | {{ $snapshot['amount'] }} |
| Related invoice | {{ $snapshot['invoice_number'] }} |

This message summarizes the receipt record. To request a copy of the official receipt or ask about this payment, use the company contact details below and quote the receipt number.

<x-mail::button :url="$snapshot['portal_url']">View related invoice</x-mail::button>

Regards,<br>
Finance

You received this email because an official receipt was issued for your payment.
</x-mail::message>
