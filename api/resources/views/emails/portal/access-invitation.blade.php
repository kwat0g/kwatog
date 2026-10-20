<x-mail::message :preheader="$preheader">
# Set up your {{ $portalType }} portal access

Hello {{ $recipientName }},

An administrator created a {{ $portalType }} portal account for you at {{ $brand['name'] }}. Set a private password with the one-time link below. It expires in 60 minutes.

<x-mail::button :url="$setupUrl">Set up portal access</x-mail::button>

If the link expires, open the portal sign-in page and request a new password setup link.

<x-mail::panel>
<strong>Security reminder</strong><br>
If you were not expecting this invitation, do not use the link. Contact {{ $brand['name'] }} through a contact method you already trust.
</x-mail::panel>

Regards,<br>
<strong>{{ $brand['name'] }} Portal Support</strong>

You received this email because an administrator invited you to a {{ $portalType }} portal account.
</x-mail::message>
