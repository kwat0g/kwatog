<x-mail::message :preheader="$preheader">
# Reset your {{ $portalType }} portal password

Hello {{ $recipientName }},

We received a request to reset the password for your {{ $brand['name'] }} {{ $portalType }} portal account.

If you made this request, use the one-time link below to choose a new password. It expires in 60 minutes. A newer request invalidates this link.

<x-mail::button :url="$resetUrl">Reset portal password</x-mail::button>

If you did not request this change, ignore this email. Your password will remain unchanged. Contact {{ $brand['name'] }} through a contact method you already trust if you need help.

Regards,<br>
{{ $brand['name'] }} Portal Support

You received this email because a password reset was requested for your {{ $portalType }} portal account.
</x-mail::message>
