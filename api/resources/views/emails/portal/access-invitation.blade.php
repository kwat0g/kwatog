<x-mail::message>
# Your {{ ucfirst($portalType) }} Portal access is ready

Hello {{ $recipientName }},

Your secure Ogami Philippines portal account is ready. Use the details below to sign in for the first time.

<x-mail::panel>
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 0 0 10px; color: #667085; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;">Portal</td>
<td align="right" style="padding: 0 0 10px; color: #344054; font-size: 14px; font-weight: 700;">{{ ucfirst($portalType) }} Portal</td>
</tr>
<tr>
<td style="padding: 10px 0 0; border-top: 1px solid #f3d6c9; color: #667085; font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;">Temporary password</td>
<td align="right" style="padding: 10px 0 0; border-top: 1px solid #f3d6c9; color: #17202a; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', monospace; font-size: 14px; font-weight: 700;">{{ $temporaryPassword }}</td>
</tr>
</table>
</x-mail::panel>

<p style="margin-bottom: 0;"><strong>Important:</strong> use this temporary password only through the secure portal. After signing in, request a password reset to set a private password known only to you.</p>

<x-mail::button :url="$portalUrl">Open {{ ucfirst($portalType) }} Portal</x-mail::button>

<x-mail::panel>
<strong>Security reminder</strong><br>
If you were not expecting this invitation, do not use the account. Contact Ogami Philippines through an approved company channel so we can verify the request.
</x-mail::panel>

Regards,<br>
<strong>Ogami Philippines Portal Support</strong>
</x-mail::message>
