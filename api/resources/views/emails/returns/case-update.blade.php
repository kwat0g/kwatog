<x-mail::message :preheader="$preheader" :title="$title">
# Your problem report has a new update

Your problem report has an update. Its status when this message was queued is **{{ $status_label }}**.

| Report detail | Information |
|---|---:|
| Reference | {{ $reference }} |
| Status | {{ $status_label }} |

<x-mail::button :url="$portalUrl">View report in your portal</x-mail::button>

Sign in to review the detailed report and any action available to your portal account. For privacy, case details and attachments stay in the authenticated portal.

You received this email because your {{ $recipient_realm }} portal account is associated with the party on this report. Verify the update by opening the portal address you normally use and checking the report there.

This is an automated notice. Use the company contact details in the footer if you need help.
</x-mail::message>
