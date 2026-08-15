<x-mail::message>
# Interview Scheduled

Dear {{ $applicantName }},

We are pleased to invite you for an interview for the **{{ $positionTitle }}** position.

**Date & Time:** {{ $scheduledAt }}<br>
**Location:** {{ $location }}<br>
**Interviewer:** {{ $interviewerName }}

Please arrive 15 minutes early and bring a valid ID.

**Company Address:**<br>
{{ $companyName }}<br>
{{ $companyAddress }}

Your tracking code is **{{ $trackingCode }}**. You can use it to view your application status:

<x-mail::button :url="$trackingUrl">Track Your Application</x-mail::button>

Regards,<br>
<strong>{{ $companyName }} HR Team</strong>
</x-mail::message>
