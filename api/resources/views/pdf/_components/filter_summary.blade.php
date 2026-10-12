{{--
    Report filter summary (WYSIWYE): every active filter, date range, scope,
    and the record count "Showing N of M". Render at the top of every report.
--}}
<div class="filter-summary">
  <span class="count">Showing {{ number_format((int) ($shown ?? 0)) }} of {{ number_format((int) ($total ?? 0)) }}</span>
  @if (!empty($filters))
    &nbsp;&middot;&nbsp;{{ collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v, $k) => "{$k}={$v}")->implode(', ') }}
  @else
    &nbsp;&middot;&nbsp;no filters
  @endif
</div>
