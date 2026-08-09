{{-- Optional seeded document view. All displayed values are supplied by the
     document record; the view does not invent business identity or content. --}}
@extends('pdf._layout', ['docTitle' => ($title ?? '') ?: 'Document'])

@section('content')
  <div class="meta-grid">
    <div class="col">
      <label>Subject</label>
      <div class="v">{{ ($subject ?? '') ?: '—' }}</div>
    </div>
    <div class="col">
      <label>Reference</label>
      <div class="v">{{ ($reference ?? '') ?: '—' }}</div>
    </div>
  </div>

  <div style="margin: 16px 0; padding: 12px;
              background: #FAFAFA; border: 1px solid #E4E4E7; border-radius: 4px;">
    <div style="font-size: 12px; line-height: 1.5;">
      {{ ($body ?? '') ?: '—' }}
    </div>
  </div>

  @if (!empty($attached_to))
    <div style="margin-top: 12px; font-size: 11px; color: #555;">
      Attached to:
      <strong style="color: #09090B;">{{ $attached_to }}</strong>
    </div>
  @endif

  @include('pdf._components.signatures', ['signatures' => [
    ['label' => 'Prepared by',  'name' => ($generated['by'] ?? '') ?: '—', 'date' => now()->format('M d, Y')],
    ['label' => 'Reviewed by',  'name' => null, 'date' => null],
    ['label' => 'Approved by',  'name' => null, 'date' => null],
  ]])
@endsection
