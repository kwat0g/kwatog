<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

{{ $greeting }},

{{ $message }}

This one-time link expires in {{ $expiryMinutes }} minutes.

<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

{{ $footerNote }}
</x-mail::message>
