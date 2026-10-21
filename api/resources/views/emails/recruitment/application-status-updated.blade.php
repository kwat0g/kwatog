<x-mail::message :preheader="'Your application for '.$positionTitle.' is now '.$currentStageLabel.'.'" title="Application status updated">
# Application status update

Hello {{ $applicantName }},

{{ $companyName }} has updated the status of your application for **{{ $positionTitle }}**.

<x-mail::panel>
**Previous status**<br>
{{ $previousStageLabel }}

**Current status**<br>
{{ $currentStageLabel }}

**Application tracking code**<br>
{{ $trackingCode }}
</x-mail::panel>

@if ($currentStageLabel === 'Not Selected')
Thank you for your interest in {{ $companyName }}. After reviewing your application, we have decided to move forward with other candidates.
@elseif ($currentStageLabel === 'Hired')
Our HR team will contact you with information about next steps.
@elseif ($currentStageLabel === 'Offer Extended')
Our HR team will contact you with the offer details and next steps.
@else
Our recruitment team will contact you if there is another update.
@endif

<x-mail::button :url="$trackingUrl">Check application status</x-mail::button>

Keep your tracking code private. You received this email because an application was submitted using this email address.

Regards,<br>
{{ $companyName }} Human Resources
</x-mail::message>
