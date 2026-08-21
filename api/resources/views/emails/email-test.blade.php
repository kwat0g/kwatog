<x-mail::message>
# AWS Mail Manager email integration test

Hello,

This is a live transactional-email test from **{{ $brand['name'] }}**.

| Detail | Value |
|---|---|
| Sender identity | {{ $brand['name'] }} |
| Legal entity | {{ $brand['legal_name'] }} |
| Address | {{ $brand['address'] }} |
| Phone | {{ $brand['phone'] }} |
| Company email | {{ $brand['email'] }} |
| Sales email | {{ $brand['sales_email'] }} |
| VAT status | {{ $brand['vat_status'] }} |
| Certification | {{ $brand['certification'] }} |

The AWS Mail Manager SMTP queue accepted this message from the Ogami Philippines application.

Thanks,<br>
{{ $brand['name'] }}
</x-mail::message>
