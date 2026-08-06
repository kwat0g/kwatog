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

Regards,<br>
HR Department<br>
{{ $companyName }}
</x-mail::message>
