<x-mail::message :preheader="$preheader" :title="$title">
# {{ $title }}

Hello {{ $decisionEmail['customer_name'] }},

Our sales team has reviewed your response for sales order **{{ $decisionEmail['so_number'] }}**.

<x-mail::panel>
Decision: {{ ucfirst($decisionEmail['decision_label']) }}. The order total below reflects the order when the decision was recorded.
</x-mail::panel>

| Order detail | Information |
|---|---:|
| Sales order | {{ $decisionEmail['so_number'] }} |
| Your response | {{ $decisionEmail['response_type_label'] }} |
| Order total | {{ $decisionEmail['total_amount'] }} |
@if ($decisionEmail['proposed_delivery_date'])
| Proposed delivery date | {{ $decisionEmail['proposed_delivery_date'] }} |
@endif

@if ($decisionEmail['decision'] === 'accept' && $decisionEmail['response_type'] === 'propose')
Your proposed order quantities and prices are reflected in the order. Review the updated order details in the customer portal.
@elseif ($decisionEmail['decision'] === 'reject')
Your response was returned for revision. Review the sales team's feedback in the customer portal before submitting another response.
@else
Open the order to review its current status and any next steps.
@endif

<x-mail::button :url="$decisionEmail['portal_url']">Review your sales order</x-mail::button>

Regards,<br>
{{ $brand['name'] }} Sales Team

You received this email because your customer portal account is associated with this sales order. To verify the message, open the customer portal using the address you normally use.
</x-mail::message>
