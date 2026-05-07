{{-- Series E demo document. Used by SeriesEDemoSeeder to populate the
     vault when no real payslips/invoices exist yet. Exercises every
     piece of the new PDF chrome (letterhead, watermark, footer, page
     number) so a fresh dev DB still gets visible vault content. --}}
@extends('pdf._layout', ['docTitle' => $title ?? 'Demo Document'])

@section('content')
  <div class="meta-grid">
    <div class="col">
      <label>Subject</label>
      <div class="v">{{ $subject ?? 'Series E vault verification' }}</div>
    </div>
    <div class="col">
      <label>Reference</label>
      <div class="v">{{ $reference ?? 'DEMO-' . now()->format('YmdHis') }}</div>
    </div>
  </div>

  <div style="margin: 16px 0; padding: 12px;
              background: #FAFAFA; border: 1px solid #E4E4E7; border-radius: 4px;">
    <div style="font-size: 12px; line-height: 1.5;">
      {{ $body ?? "This is a system-generated sample document produced by the
          Ogami ERP document vault seeder. It exercises the shared PDF chrome
          (letterhead, footer, optional watermark) so reviewers can confirm the
          vault, preview, and download surfaces work end-to-end without needing
          real payroll or invoicing data in the database." }}
    </div>
  </div>

  @if (!empty($attached_to))
    <div style="margin-top: 12px; font-size: 11px; color: #555;">
      Attached to:
      <strong style="color: #09090B;">{{ $attached_to }}</strong>
    </div>
  @endif

  @include('pdf._components.signatures', ['signatures' => [
    ['label' => 'Prepared by',  'name' => $generated['by'] ?? 'system', 'date' => now()->format('M d, Y')],
    ['label' => 'Reviewed by',  'name' => null, 'date' => null],
    ['label' => 'Approved by',  'name' => null, 'date' => null],
  ]])
@endsection
