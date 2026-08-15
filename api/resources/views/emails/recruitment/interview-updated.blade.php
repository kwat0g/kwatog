<x-mail::message>
# Interview details updated

Dear {{ $applicantName }},

The interview details for your **{{ $positionTitle }}** application have been updated.

| Detail | Information |
|---|---|
| Date & time | **{{ $scheduledAt }}** |
| Location | {{ $location }} |
| Interviewer | {{ $interviewerName }} |
@if ($outcome)
| Interview outcome | **{{ $outcome }}** |
@endif
| Tracking code | **{{ $trackingCode }}** |

Please use the updated information above. If you have questions or cannot attend, contact the {{ $companyName }} HR Team through the contact details in this email.

<x-mail::button :url="$trackingUrl">Track Your Application</x-mail::button>

Regards,<br>
<strong>{{ $companyName }} HR Team</strong>
</x-mail::message>
