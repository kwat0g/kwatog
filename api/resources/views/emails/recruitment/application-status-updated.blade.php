<x-mail::message :preheader="$preheader" :title="$title">
# Application status update

Hello {{ $applicantNameForEmail }},

There is an update to your application for **{{ $positionTitleForEmail }}** at {{ $companyNameForEmail }}.

<x-mail::panel>
Previous status: {{ $previousStageLabel }}<br>
Current status: **{{ $currentStageLabel }}**
</x-mail::panel>

@if ($currentStageLabel === 'Not Selected')
Thank you for your interest in {{ $companyNameForEmail }}. We will not be progressing your application for this position.
@elseif ($currentStageLabel === 'Hired')
Our recruitment team will contact you about next steps.
@elseif ($currentStageLabel === 'Offer Extended')
Our recruitment team will contact you about the offer and next steps.
@else
Our recruitment team will contact you if there is another update.
@endif

Your tracking code: **{{ $trackingCodeForEmail }}**

<x-mail::button :url="$trackingUrlForEmail">View application status</x-mail::button>

Regards,<br>
{{ $companyNameForEmail }} Recruitment Team

You received this email because your application status changed. Keep your tracking code private.
</x-mail::message>
