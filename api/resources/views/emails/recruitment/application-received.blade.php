<x-mail::message>
# Application Received

Dear {{ $applicantName }},

Thank you for your interest in {{ $companyName }}. We have received your application for the **{{ $positionTitle }}** position.

Your tracking code is: **{{ $trackingCode }}**

You can check your application status at any time using this code:

<x-mail::button :url="$trackingUrl">
Track Your Application
</x-mail::button>

We will review your application and get back to you.

Regards,<br>
HR Department<br>
{{ $companyName }}
</x-mail::message>
