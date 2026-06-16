<x-mail::message>
# Your notification summary

Hi {{ $recipientName ?? 'there' }},

You have **{{ $totalUnread }}** unread notification(s). Here are the most recent:

@foreach ($items as $item)
**{{ $item['title'] }}**
{{ $item['message'] }}
<br>
@endforeach

@if ($totalUnread > count($items))
…and {{ $totalUnread - count($items) }} more.
@endif

<x-mail::button :url="rtrim(config('app.frontend_url', config('app.url')), '/') . '/notifications'">
View All Notifications
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
