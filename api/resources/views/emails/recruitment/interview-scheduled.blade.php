<x-mail::message :preheader="'Interview for '.$positionTitle.' on '.$scheduledAt.'.'" title="Interview scheduled">
# Interview invitation

Hello {{ $applicantName }},

Your interview for **{{ $positionTitle }}** is scheduled. Please review the details below and contact {{ $companyName }} Human Resources if you cannot attend.

<x-mail::panel>
**Date and time**<br>
{{ $scheduledAt }}

**Interview location**<br>
{{ $location }}

**Interviewer**<br>
{{ $interviewerName }}

**Application tracking code**<br>
{{ $trackingCode }}
</x-mail::panel>

Please arrive 15 minutes early and bring a valid ID.

**Company address**<br>
{{ $companyName }}<br>
{{ $companyAddress }}

<x-mail::button :url="$trackingUrl">Check application status</x-mail::button>

You received this email because an interview was scheduled for your application.

Regards,<br>
{{ $companyName }} Human Resources
</x-mail::message>
