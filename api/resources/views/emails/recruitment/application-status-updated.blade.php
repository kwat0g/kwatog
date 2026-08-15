<x-mail::message>
# Your application status has been updated

Dear {{ $applicantName }},

There is an update to your application for the **{{ $positionTitle }}** position at {{ $companyName }}.

| Detail | Information |
|---|---|
| Previous status | {{ $previousStage }} |
| Current status | **{{ $currentStage }}** |
| Tracking code | **{{ $trackingCode }}** |

@if ($currentStage === 'Not Selected')
Thank you for your interest in {{ $companyName }}. After review, we have decided to move forward with other candidates for this position.
@elseif ($currentStage === 'Hired')
Congratulations. Our HR team will contact you with the next steps.
@elseif ($currentStage === 'Offer Extended')
Our HR team will contact you with the offer details and next steps.
@else
Our recruitment team will continue to review your application and will contact you when there is another update.
@endif

<x-mail::button :url="$trackingUrl">Track Your Application</x-mail::button>

Regards,<br>
<strong>{{ $companyName }} HR Team</strong>
</x-mail::message>
