<x-mail::message :preheader="'Official receipt '.$snapshot['or_number'].' for your payment.'" :title="'Official receipt '.$snapshot['or_number'].' issued'">
# Official receipt {{ $snapshot['or_number'] }} issued

Hello {{ $snapshot['customer_name'] }},

An official receipt has been issued for your payment.

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
