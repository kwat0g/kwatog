<x-mail::message>
# Customer complaint update

Hello {{ $complaint->customer?->contact_person ?: $complaint->customer?->name ?: 'Customer' }},

Your complaint **{{ $complaint->complaint_number }}** has been updated by {{ config('mail.from.name', 'Ogami Philippines') }}.

| Detail | Value |
|---|---|
| Status | {{ $complaint->status?->label() ?? $complaint->status }} |
| Severity | {{ $complaint->severity?->label() ?? $complaint->severity }} |
| Description | {{ $complaint->description }} |
| Affected quantity | {{ $complaint->affected_quantity }} |
@if ($complaint->product)
| Product | {{ $complaint->product->part_number }} — {{ $complaint->product->name }} |
@endif

We will continue to record containment, root-cause analysis, corrective action, and verification in the 8D process where applicable.

@if (! empty($portalUrl))
<x-mail::button :url="$portalUrl">View complaint status</x-mail::button>
@endif

Regards,<br>
Ogami Philippines Customer Support
</x-mail::message>
