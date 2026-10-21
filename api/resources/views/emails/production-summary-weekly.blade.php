<x-mail::message :preheader="$preheader" :title="$title">
# Weekly production summary

**{{ \Illuminate\Support\Carbon::parse($summary['range_start'])->format('j M Y') }} – {{ \Illuminate\Support\Carbon::parse($summary['range_end'])->format('j M Y') }}**

## Production totals

| Measure | Result |
|:--|--:|
| Good units | {{ number_format($summary['good']) }} |
| Rejected units | {{ number_format($summary['reject']) }} |
| Week-over-week change in good units | {{ $summary['wow_delta_pct'] === null ? 'Not comparable; prior week had no good units' : ($summary['wow_delta_pct'] > 0 ? '+' : '').$summary['wow_delta_pct'].'%' }} |

The comparison uses the same seven-day period immediately before this report.

<x-mail::button :url="$workOrdersUrl">
Open production work orders
</x-mail::button>

You receive this weekly report because your role is configured for production summaries.
</x-mail::message>
