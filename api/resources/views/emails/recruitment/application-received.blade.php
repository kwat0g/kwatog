<x-mail::message :preheader="'We received your application for '.$positionTitle.'.'" title="Application received">
# Application received

Hello {{ $applicantName }},

Thank you for applying to **{{ $positionTitle }}**. {{ $companyName }} has received your application. Our recruitment team will review it and contact you if there is an update.

<x-mail::panel>
**Position**<br>
{{ $positionTitle }}

**Application tracking code**<br>
{{ $trackingCode }}
</x-mail::panel>

Keep this code private. You will need it to check your application status.

<x-mail::button :url="$trackingUrl">
Check application status
</x-mail::button>

Regards,<br>
{{ $companyName }} Human Resources

You received this email because an application was submitted using this email address.
</x-mail::message>
