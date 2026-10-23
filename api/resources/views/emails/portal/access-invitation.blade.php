<x-mail::message :preheader="$preheader" :title="'Your '.ucfirst($portalType).' portal access is ready'">
# Your {{ ucfirst($portalType) }} Portal access is ready

Hello {{ $recipientName }},

Your {{ $portalType }} portal account is ready. Set a private password using the secure link below. The link expires in 60 minutes and can be used once.

<x-mail::button :url="$setupUrl">Set up portal access</x-mail::button>

If the link expires, open the portal sign-in page and request a new password reset link.

<x-mail::panel>
<strong>Security reminder</strong><br>
If you were not expecting this invitation, do not use the link. Contact the company through an approved channel so the request can be verified.
</x-mail::panel>

Regards,<br>
<strong>{{ config('mail.from.name', config('app.name')) }} Portal Support</strong>
</x-mail::message>
