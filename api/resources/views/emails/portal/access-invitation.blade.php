<x-mail::message>
# Your {{ ucfirst($portalType) }} Portal access is ready

Hello {{ $recipientName }},

Your Ogami Philippines {{ $portalType }} portal account is ready. Set a private password using the secure link below. The link expires in 60 minutes and can be used once.

<x-mail::button :url="$setupUrl">Set up portal access</x-mail::button>

If the link expires, open the portal sign-in page and request a new password reset link.

<x-mail::panel>
<strong>Security reminder</strong><br>
If you were not expecting this invitation, do not use the link. Contact Ogami Philippines through an approved company channel so we can verify the request.
</x-mail::panel>

Regards,<br>
<strong>Ogami Philippines Portal Support</strong>
</x-mail::message>
