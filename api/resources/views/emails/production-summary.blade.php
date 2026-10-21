<x-mail::message :preheader="$preheader" :title="$title">
# Daily production summary

**{{ \Illuminate\Support\Carbon::parse($summary['date'])->format('j M Y') }}**

## Production totals

| Measure | Result |
|:--|--:|
| Good units | {{ number_format($summary['totals']['good']) }} |
| Rejected units | {{ number_format($summary['totals']['reject']) }} |
| Total units | {{ number_format($summary['totals']['total_units']) }} |
| Scrap rate | {{ $summary['totals']['scrap_rate'] === null ? 'Not available' : $summary['totals']['scrap_rate'].'%' }} |

## Quality checks

{{ number_format($summary['qc']['passed']) }} passed · {{ number_format($summary['qc']['failed']) }} failed · {{ number_format($summary['qc']['total']) }} total

@if (! empty($summary['wos']))
## Work orders

@foreach (array_slice($summary['wos'], 0, 10) as $wo)
**{{ $wo['wo_number'] }}** · {{ \Illuminate\Support\Str::squish(strip_tags((string) ($wo['product_name'] ?? ''))) ?: '—' }}<br />
Target {{ number_format($wo['quantity_target']) }} · Good {{ number_format($wo['good']) }} · Reject {{ number_format($wo['reject']) }} · {{ \Illuminate\Support\Str::headline($wo['status']) }}

@endforeach
@if (count($summary['wos']) > 10)
@php($moreWorkOrders = count($summary['wos']) - 10)

{{ $moreWorkOrders }} more work order{{ $moreWorkOrders === 1 ? '' : 's' }} {{ $moreWorkOrders === 1 ? 'is' : 'are' }} available in OGAMI ERP.
@endif
@endif

@if (! empty($summary['breakdowns']))
## Machine breakdowns

@foreach (array_slice($summary['breakdowns'], 0, 5) as $breakdown)
**{{ $breakdown['machine_code'] }}** · {{ \Illuminate\Support\Str::squish(strip_tags((string) $breakdown['machine_name'])) ?: '—' }}<br />
{{ $breakdown['end_time'] === 'ongoing' ? 'Ongoing' : number_format($breakdown['duration_min']).' min' }}

@endforeach
@if (count($summary['breakdowns']) > 5)
@php($moreBreakdowns = count($summary['breakdowns']) - 5)

{{ $moreBreakdowns }} more breakdown{{ $moreBreakdowns === 1 ? '' : 's' }} {{ $moreBreakdowns === 1 ? 'is' : 'are' }} available in OGAMI ERP.
@endif
@endif

@if (! empty($summary['defects']))
## Most reported defects

| Defect | Count |
|:--|--:|
@foreach ($summary['defects'] as $defect)
| {{ $defect['code'] }} · {{ \Illuminate\Support\Str::squish(strip_tags((string) $defect['name'])) ?: '—' }} | {{ number_format($defect['count']) }} |
@endforeach
@endif

<x-mail::button :url="$workOrdersUrl">
Open production work orders
</x-mail::button>

You receive this daily report because your role is configured for production summaries.
</x-mail::message>
