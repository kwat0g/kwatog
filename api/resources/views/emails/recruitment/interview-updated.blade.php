<x-mail::message :preheader="$preheader" :title="$title">
# Interview details updated

Hello {{ $applicantName }},

The interview details for your **{{ $positionTitle }}** application have changed. Please use the updated details below.

<x-mail::panel>
Date and time: **{{ $scheduledAt }}**<br>
Location or meeting details: {{ $location }}<br>
Interviewer: {{ $interviewerName }}
</x-mail::panel>

Your tracking code: **{{ $trackingCode }}**

<x-mail::button :url="$trackingUrl">View application status</x-mail::button>

Regards,<br>
{{ $companyName }} Recruitment Team

You received this email because interview details for your application changed. Contact the recruitment team if you cannot attend.
</x-mail::message>
