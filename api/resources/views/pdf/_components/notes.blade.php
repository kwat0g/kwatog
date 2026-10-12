{{--
    Notes / terms block: payment terms, remarks, delivery terms, standard T&Cs.
    $terms falls back to the `pdf.terms_conditions` setting (injected as
    $company['terms']); per-document $notes print above it.
--}}
@if (!empty($notes) || !empty($company['terms'] ?? null))
  <div class="notes-block">
    <div class="notes-title">{{ $notesTitle ?? 'Notes & Terms' }}</div>
    @if (!empty($notes))
      <div style="margin-bottom:4px;">{{ $notes }}</div>
    @endif
    @if (!empty($company['terms'] ?? null))
      <div>{{ $company['terms'] }}</div>
    @endif
  </div>
@endif
