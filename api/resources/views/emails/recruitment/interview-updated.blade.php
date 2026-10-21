<x-mail::message :preheader="'Interview details for '.$positionTitle.' were updated.'" title="Interview details updated">
# Interview details updated

Hello {{ $applicantName }},

The interview details for your **{{ $positionTitle }}** application have changed. Please use the updated information below. Contact {{ $companyName }} Human Resources if you cannot attend.

<x-mail::panel>
**Date and time**<br>
{{ $scheduledAt }}

**Interview location**<br>
{{ $location }}

**Interviewer**<br>
{{ $interviewerName }}

@if ($outcome)
**Interview outcome**<br>
{{ $outcome }}

@endif
**Application tracking code**<br>
{{ $trackingCode }}
</x-mail::panel>

<x-mail::button :url="$trackingUrl">Check application status</x-mail::button>

You received this email because an interview was updated for your application.

Regards,<br>
{{ $companyName }} Human Resources
</x-mail::message>
