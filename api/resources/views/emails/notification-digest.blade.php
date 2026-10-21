<x-mail::message :preheader="$preheader" :title="$title">
# Notification summary

Hi {{ $recipientName ?? 'there' }},

You have **{{ number_format($totalUnread) }}** unread notifications in OGAMI ERP.

Sign in to review them in the application. Notification details stay behind your account login.

You receive this message because notification digests are enabled in your account preferences.

<x-mail::button :url="rtrim(config('app.frontend_url', config('app.url')), '/') . '/notifications'">
Review notifications
</x-mail::button>
</x-mail::message>
