<x-mail::message :preheader="$preheader" :title="$pageTitle">
# {{ $title }}

Hello {{ $recipientName }},

{{ $body }}

@if ($linkUrl)
<x-mail::button :url="$linkUrl">Open in OGAMI</x-mail::button>
Sign in to OGAMI to review this notification. Any available action requires you to use the page.
@endif

You received this notification because OGAMI sent an update about your account or a business record associated with it.

For help, use the company contact details below.
</x-mail::message>
