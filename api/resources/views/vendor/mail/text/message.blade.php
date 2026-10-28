@php($brand = app(\App\Common\Services\EmailBrandingService::class)->data())
<x-mail::layout>
<x-slot:header>
{{ $brand['name'] }}
</x-slot:header>

{{ $slot }}

@isset($subcopy)
<x-slot:subcopy>
{{ $subcopy }}
</x-slot:subcopy>
@endisset

<x-slot:footer>
{{ $brand['name'] }}
{{ $brand['legal_name'] }}
{{ $brand['address'] }}
@if ($brand['phone'])
{{ $brand['phone'] }}
@endif
@if ($brand['email'])
{{ $brand['email'] }}
@endif
@if ($brand['footer_disclaimer'] ?? null)
{{ $brand['footer_disclaimer'] }}
@endif
Automated message.
</x-slot:footer>
</x-mail::layout>
