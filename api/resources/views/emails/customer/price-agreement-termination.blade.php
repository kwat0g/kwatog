<x-mail::message :preheader="$preheader" :title="$title">
Hello {{ $notice['customer_name'] }},

@if ($notice['event'] === 'signature_request')
An agreement change for **{{ $notice['part_number'] }} · {{ $notice['product_name'] }}** is waiting in your customer portal.

@if ($notice['scheduled'])
An authorized signatory can review and sign the cancellation before the scheduled agreement begins.
@else
The current price terms remain in effect until an authorized customer signatory signs the termination document.
@endif

Document: **{{ $notice['document_name'] }}**

@elseif ($notice['event'] === 'request_cancelled')
The pending signature request for **{{ $notice['part_number'] }} · {{ $notice['product_name'] }}** was cancelled by your account manager. The price agreement itself remains unchanged.

@elseif ($notice['scheduled'])
An authorized signatory cancelled the scheduled agreement for **{{ $notice['part_number'] }} · {{ $notice['product_name'] }}** before it began. It will not take effect.

@else
An authorized signatory ended the price agreement for **{{ $notice['part_number'] }} · {{ $notice['product_name'] }}**. The agreed price remains valid through **{{ $notice['last_valid_date'] ?? 'the date recorded in the signed document' }}**.
@endif

<x-mail::button :url="$notice['portal_url']">Open customer agreements</x-mail::button>

If you did not expect this update, contact your Ogami account manager.

Regards,<br>
{{ $brand['name'] }}
</x-mail::message>
