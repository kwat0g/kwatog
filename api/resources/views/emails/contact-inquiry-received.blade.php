<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

A new inquiry from the company website is waiting for review.

**Reference:** {{ $inquiryNumber }}<br />
**Submitted:** {{ $submittedAt }}

The sender's contact details and message are available to authorized CRM users.

<x-mail::button :url="$inquiryUrl">
Open inquiry in CRM
</x-mail::button>

Sign in to OGAMI to review the inquiry and update its status.

You received this alert because this address is configured for website contact inquiries.
</x-mail::message>
