<x-mail::message>
# {{ $reference }}

Your report has an update. Current status: **{{ $statusLabel }}**.

Open the report to see the agreed action, provide information, or check delivery and credit progress.

<x-mail::button :url="$portalUrl">View report</x-mail::button>

Ogami Customer Service and Purchasing
</x-mail::message>
