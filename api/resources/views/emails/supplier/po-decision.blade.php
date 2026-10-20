<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

Hello {{ $decisionEmail['vendor_name'] }},

Purchasing has reviewed your response for purchase order **{{ $decisionEmail['po_number'] }}**.

<x-mail::panel>
Decision: {{ ucfirst($decisionEmail['decision_label']) }}. The order total below reflects the purchase order when the decision was recorded.
</x-mail::panel>

| Purchase order detail | Information |
|---|---:|
| Purchase order | {{ $decisionEmail['po_number'] }} |
| Your response | {{ $decisionEmail['response_type_label'] }} |
| Order total | {{ $decisionEmail['total_amount'] }} |
@if ($decisionEmail['proposed_delivery_date'])
| Proposed delivery date | {{ $decisionEmail['proposed_delivery_date'] }} |
@endif

@if ($decisionEmail['decision'] === 'accept' && $decisionEmail['response_type'] === 'propose')
Your proposed quantities and prices were applied to the order. Review the updated order details in the supplier portal before arranging delivery.
@elseif ($decisionEmail['decision'] === 'reject')
Your response was returned for revision. Review the purchasing team's feedback in the supplier portal before submitting another response.
@else
Open the purchase order to review its current status and any next steps.
@endif

<x-mail::button :url="$decisionEmail['portal_url']">Review your purchase order</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Purchasing Team

You received this email because your supplier portal account is associated with this purchase order. To verify this message, open the supplier portal using the address you normally use.
</x-mail::message>
