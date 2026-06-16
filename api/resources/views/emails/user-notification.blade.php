<x-mail::message>
# {{ $title }}

Hi {{ $recipientName ?? 'there' }},

{{ $body }}

@if (! empty($linkTo))
<x-mail::button :url="rtrim(config('app.frontend_url', config('app.url')), '/') . $linkTo">
View Details
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
