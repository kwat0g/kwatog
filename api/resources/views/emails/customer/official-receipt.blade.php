<x-mail::message>
# Official receipt {{ $snapshot['or_number'] }} issued

Hello {{ $snapshot['customer_name'] }},

Ogami Philippines has issued an official receipt for your payment.

| Detail | Value |
|---|---|
| Official receipt | {{ $snapshot['or_number'] }} |
| Receipt date | {{ $snapshot['date'] }} |
| Amount received | {{ $snapshot['amount'] }} |
| Invoice | {{ $snapshot['invoice_number'] }} |

Please retain this message for your records.

<x-mail::button :url="$snapshot['portal_url']">View related invoice</x-mail::button>

Regards,<br>
Ogami Philippines Finance
</x-mail::message>
