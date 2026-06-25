@extends('pdf._layout')
@section('title', 'Audit Trail Report')

@section('content')
  <div class="doc-title">Audit Trail Report</div>

  <div class="meta-grid">
    <div class="col">
      <label>Generated</label>
      <div class="v">{{ $generated['at_text'] }}</div>
      <label style="margin-top:6px">By</label>
      <div>{{ $generated['by'] }}</div>
    </div>
    <div class="col">
      <label>Filters</label>
      <div>{{ $filterSummary }}</div>
      <label style="margin-top:6px">Total Entries</label>
      <div class="v">{{ count($logs) }}</div>
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th style="width: 110px;">Date / Time</th>
        <th style="width: 100px;">User</th>
        <th style="width: 60px;">Action</th>
        <th style="width: 120px;">Record</th>
        <th>Changes</th>
      </tr>
    </thead>
    <tbody>
      @forelse ($logs as $log)
        @php
          $old = (array) ($log->old_values ?? []);
          $new = (array) ($log->new_values ?? []);
          $basename = class_basename((string) $log->model_type);
          $changedFields = [];
          foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
              if (($old[$k] ?? null) !== ($new[$k] ?? null)) {
                  $changedFields[] = ucfirst(str_replace('_', ' ', $k));
              }
          }
          $summary = match ($log->action) {
              'created' => 'Created',
              'deleted' => 'Deleted',
              default   => count($changedFields) > 0
                  ? 'Changed: ' . implode(', ', array_slice($changedFields, 0, 6))
                    . (count($changedFields) > 6 ? ', ...' : '')
                  : 'Updated (no field diff)',
          };
        @endphp
        <tr>
          <td style="font-family: 'DejaVu Sans Mono', monospace; font-size: 8px;">
            {{ $log->created_at?->format('Y-m-d H:i') ?? '—' }}
          </td>
          <td>{{ $log->user?->name ?? 'System' }}</td>
          <td>
            <span class="chip">{{ ucfirst($log->action) }}</span>
          </td>
          <td style="font-family: 'DejaVu Sans Mono', monospace; font-size: 8px;">
            {{ $basename }} #{{ $log->model_id }}
          </td>
          <td style="font-size: 8px;">{{ $summary }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="5" style="text-align: center; padding: 16px; color: #999;">
            No audit log entries match the selected filters.
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>

  @if (count($logs) >= 500)
    <p style="font-size: 8px; color: #999; margin-top: 8px;">
      * Output capped at 500 rows. Narrow the filters for a complete trail.
    </p>
  @endif
@endsection
