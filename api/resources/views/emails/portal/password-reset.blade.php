<x-mail::message>
# Reset your portal password

Hello {{ $recipientName }},

We received a request to reset the password for your Ogami Philippines {{ $portalType }} portal account.

Use the secure button below to choose a new password. This link expires in 60 minutes and can be used only once.

<x-mail::button :url="$resetUrl">Reset portal password</x-mail::button>

If you did not request this change, you can ignore this message. Your password will remain unchanged.

Regards,<br>
Ogami Philippines Portal Support
</x-mail::message>
