<x-mail::message :preheader="$preheader" :title="$title">
# Application received

Hello {{ $applicantName }},

We received your application for **{{ $positionTitle }}**. Our recruitment team will review it and contact you if there is an update.

<x-mail::panel>
Your tracking code: **{{ $trackingCode }}**
</x-mail::panel>

Use the tracking code on the careers page to check your application status.

<x-mail::button :url="$trackingUrl">Track your application</x-mail::button>

Regards,<br>
{{ $companyName }} Recruitment Team

You received this email because an application was submitted with this email address. Keep the tracking code private.
</x-mail::message>
