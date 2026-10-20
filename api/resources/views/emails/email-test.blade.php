<x-mail::message :preheader="$preheader" title="AWS Mail Manager email integration test">
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

This message renders the shared email shell for preview and test.

Thanks,<br>
{{ $brand['name'] }}
</x-mail::message>
