<x-mail::message :preheader="$preheader" :title="$title">
# {{ $alertTitle }}

**{{ $severity }} alert · {{ $alertType }}**

{{ $message }}

@if ($entityType)
**Related area:** {{ $entityType }}
@endif

@if ($createdAt)
**Recorded:** {{ $createdAt }}
@endif

Review the active alert and follow the response process for this area.

<x-mail::button :url="$alertsUrl">
Review alerts
</x-mail::button>

You receive this message because your role is configured for critical system alerts. Manage the alert in OGAMI ERP.
</x-mail::message>
