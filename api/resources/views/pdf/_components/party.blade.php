{{--
    Party block: one reusable bill-to / ship-to / vendor / employee card.
    Nulls and empty strings render as a dash — never blank holes, never "null".

    Required: $title, $name. Optional: $lines (array of strings|null),
    $meta (array of [label, value] rows), $last (bool — right-column card).
--}}
@php
  $dash = static fn ($v): string => (is_string($v) && trim($v) !== '') ? $v : '—';
@endphp
<div class="info-card @if (!empty($last)) last @endif">
  <div class="info-card-title">{{ $title }}</div>
  <div style="font-weight:bold; font-size:10pt; color:#1f1b16; margin-bottom:2px;">{{ $dash($name ?? null) }}</div>
  @foreach (($lines ?? []) as $line)
    <div style="font-size:8pt; color:#4a4239; margin-bottom:2px;">{{ $dash($line) }}</div>
  @endforeach
  @foreach (($meta ?? []) as $row)
    <div class="info-row"><span class="lbl">{{ $row[0] }}:</span> <span class="val">{{ $dash($row[1] ?? null) }}</span></div>
  @endforeach
</div>
