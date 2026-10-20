<x-mail::message :preheader="$preheader" :title="$title">
# Interview scheduled

Hello {{ $applicantName }},

An interview has been scheduled for your **{{ $positionTitle }}** application.

<x-mail::panel>
Date and time: **{{ $scheduledAt }}**<br>
Location or meeting details: {{ $location }}<br>
Interviewer: {{ $interviewerName }}
</x-mail::panel>

Your tracking code: **{{ $trackingCode }}**

<x-mail::button :url="$trackingUrl">View application status</x-mail::button>

Regards,<br>
{{ $companyName }} Recruitment Team

You received this email because an interview was scheduled for your application. Contact the recruitment team if you cannot attend.
</x-mail::message>
