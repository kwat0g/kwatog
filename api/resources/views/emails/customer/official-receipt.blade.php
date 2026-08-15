<x-mail::message>
# Official receipt issued

Hello {{ $receipt->customer?->contact_person ?: $receipt->customer?->name ?: 'Customer' }},

Ogami Philippines has issued an official receipt for your payment.

| Detail | Value |
|---|---|
| Official receipt | {{ $receipt->or_number }} |
| Receipt date | {{ optional($receipt->date)->format('F j, Y') }} |
| Amount received | {{ number_format((float) $receipt->amount, 2) }} |
@if ($receipt->invoice)
| Invoice | {{ $receipt->invoice->invoice_number }} |
@endif

Please retain this message for your records.

<x-mail::button :url="$portalUrl">View account documents</x-mail::button>

Regards,<br>
Ogami Philippines Finance
</x-mail::message>
