@extends('pdf._layout')

@section('title', 'Certificate of Conformance ' . ($coc_number ?? ''))

@section('content')

<table class="info-grid">
  <tr>
    <td>
      <div class="info-card">
        <div class="info-card-title">Certificate & Quality Spec</div>
        <div class="info-row"><span class="lbl">Certificate No:</span> <span class="val mono" style="font-weight:bold;">{{ $coc_number }}</span></div>
        <div class="info-row"><span class="lbl">Issued Date:</span> <span class="val mono">{{ $issued_at }}</span></div>
        <div class="info-row"><span class="lbl">Inspection No:</span> <span class="val mono">{{ $inspection_number }}</span></div>
        @if (!empty($quality_standard))
          <div class="info-row"><span class="lbl">Quality Standard:</span> <span class="val font-semibold" style="color:#1E1B4B;">{{ $quality_standard }}</span></div>
        @endif
      </div>
    </td>
    <td>
      <div class="info-card last">
        <div class="info-card-title">Product & Batch Traceability</div>
        <div class="info-row"><span class="lbl">Part Number:</span> <span class="val mono font-semibold">{{ $product_part_number }}</span></div>
        <div class="info-row"><span class="lbl">Description:</span> <span class="val">{{ $product_name }}</span></div>
        @if (!empty($batch_number))
          <div class="info-row"><span class="lbl">Batch No:</span> <span class="val mono font-semibold">{{ $batch_number }}</span></div>
        @endif
        @if (!empty($lot_number))
          <div class="info-row"><span class="lbl">Shipment Lot:</span> <span class="val mono font-semibold">{{ $lot_number }}</span></div>
        @endif
      </div>
    </td>
  </tr>
</table>

<table class="lines">
  <thead>
    <tr>
      <th>Inspection Parameter / Characteristic</th>
      <th style="width: 90px;" class="r">Batch Qty</th>
      <th style="width: 100px;" class="r">{{ ($evidence_method ?? null) === 'confirmed_checks' ? 'Coverage' : 'Sample Plan' }}</th>
      <th style="width: 80px;" class="r">Defects</th>
      <th style="width: 100px;" class="c">Result</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>
        <div style="font-weight:bold; color:#0F172A;">{{ ucfirst(str_replace('_', ' ', $stage)) }} Inspection</div>
        <div style="font-size:7.5pt; color:#64748B;">
          @if (!empty($aql_level)) Sampling per ANSI/ASQ Z1.4 General Level {{ $aql_level }} @endif
        </div>
      </td>
      <td class="r mono font-semibold">{{ number_format($batch_quantity) }}</td>
      <td class="r mono">@if (($evidence_method ?? null) === 'confirmed_checks') Full batch @else {{ number_format($sample_size) }} @if ($aql_code)<span style="font-size:7.5pt; color:#64748B;">[{{ $aql_code }}]</span>@endif @endif</td>
      <td class="r mono" style="{{ $defect_count > 0 ? 'color:#DC2626; font-weight:bold;' : '' }}">{{ $defect_count }}</td>
      <td class="c">
        @php($__pass = !empty($inspection_passed))
        <span class="chip {{ $__pass ? 'chip-success' : 'chip-danger' }}">
          {{ ($inspection_result ?? '') ?: '—' }}
        </span>
      </td>
    </tr>

    @if (!empty($delivery_number))
      <tr>
        <td><strong>Delivery Note Reference</strong></td>
        <td colspan="4" class="mono font-semibold">{{ $delivery_number }}</td>
      </tr>
    @endif

    @if (!empty($material_lot_references) && is_array($material_lot_references))
      <tr>
        <td colspan="5">
          <div style="font-weight:bold; font-size:8pt; color:#475569; margin-bottom:4px; text-transform:uppercase;">
            Traceable Raw Material Lots (IATF 16949 Chain Genealogy)
          </div>
          @foreach ($material_lot_references as $ref)
            <div style="font-size:8pt; font-family:'DejaVu Sans Mono', monospace; color:#334155; margin-bottom:2px;">
              &bull; {{ $ref['item_code'] ?? '—' }}
              @if (!empty($ref['grn_number'])) &middot; GRN: {{ $ref['grn_number'] }} @endif
              @if (!empty($ref['material_lot_number'])) &middot; Resin Lot: {{ $ref['material_lot_number'] }} @endif
              @if (!empty($ref['supplier_lot_reference'])) &middot; Supplier Lot: {{ $ref['supplier_lot_reference'] }} @endif
            </div>
          @endforeach
        </td>
      </tr>
    @endif
  </tbody>
</table>

@if (($evidence_method ?? null) === 'confirmed_checks')
  <p>Confirmed physical coverage: {{ $inspected_quantity ?? $batch_quantity }} pieces. Defective pieces: {{ $defect_count }}. Inspector: {{ $inspector_name ?: '—' }}. Reviewer: {{ $reviewer_name ?: '—' }}.</p>
  <p>{{ $measurement_disclosure }}</p>
  @if (!empty($confirmed_requirements))
    <table class="lines">
      <thead><tr><th>Requirement</th><th>Target / limits / acceptance reference</th><th class="c">Critical</th></tr></thead>
      <tbody>
        @foreach ($confirmed_requirements as $requirement)
          <tr>
            <td style="font-weight:bold;">{{ $requirement['parameter'] }}</td>
            <td>
              @if ($requirement['nominal'] !== null) Target: {{ $requirement['nominal'] }} {{ $requirement['unit'] }} @endif
              @if ($requirement['minimum'] !== null || $requirement['maximum'] !== null)
                @if ($requirement['nominal'] !== null)<br>@endif
                Limits: {{ $requirement['minimum'] ?? '—' }} to {{ $requirement['maximum'] ?? '—' }} {{ $requirement['unit'] }}
              @endif
              @if (trim((string) $requirement['criterion']) !== '')
                @if ($requirement['nominal'] !== null || $requirement['minimum'] !== null || $requirement['maximum'] !== null)<br>@endif
                {{ $requirement['criterion'] }}
              @endif
            </td>
            <td class="c">{{ $requirement['critical'] ? 'Yes' : 'No' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
@elseif (!empty($counted_capture))
<p>Counted coverage: {{ $inspected_quantity ?? $batch_quantity }} pieces. Defective pieces: {{ $sample_defect_count ?? $defect_count }}. Result: PASS.</p>
@endif
@if (($capture_method ?? 'measurement') === 'checklist' && empty($counted_capture))
  <p>PASS/FAIL checklist, revision {{ $checklist_revision ?? '—' }}. Source: {{ $source_quantity }} {{ $source_uom_code }}.
    Inspected: {{ $inspected_quantity }} pieces. {{ $inspected_quantity == $batch_quantity ? 'Entire batch inspected.' : 'Planned sample inspected.' }}
    Inspector: {{ $inspector_name }}. Checker: {{ $reviewer_name }}.</p>
  <table class="lines"><thead><tr><th>Check</th><th>Acceptance criterion / reference</th><th>Decision</th></tr></thead><tbody>
  @foreach ($checklist_results as $check)
    <tr><td>{{ $check['name'] }}</td><td>{{ $check['criterion'] }}
      @if ($check['nominal'] !== null)<br>Nominal: {{ $check['nominal'] }} {{ $check['unit'] }}@endif
      @if ($check['minimum'] !== null)<br>Minimum: {{ $check['minimum'] }} {{ $check['unit'] }}@endif
      @if ($check['maximum'] !== null)<br>Maximum: {{ $check['maximum'] }} {{ $check['unit'] }}@endif
      @if (($check['measured'] ?? null) !== null)<br>Actual size: {{ $check['measured'] }} {{ $check['unit'] }}@endif
    </td><td>{{ $check['decision'] }}</td></tr>
  @endforeach
  </tbody></table>
@endif

@if (!empty($critical_measurements) && is_array($critical_measurements))
  <div style="font-size:8.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; color:#1E1B4B; margin: 14px 0 6px;">
    Critical Dimension Inspection Results
  </div>
  <table class="lines">
    <thead>
      <tr>
        <th>Dimension Characteristic</th>
        <th style="width: 70px;" class="r">Nominal</th>
        <th style="width: 100px;" class="r">Tolerance</th>
        <th style="width: 110px;" class="r">Actual Range</th>
        <th style="width: 40px;" class="c">n</th>
        <th style="width: 70px;" class="c">Status</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($critical_measurements as $m)
        <tr>
          <td style="font-weight:bold;">
            {{ $m['parameter'] }}
            @if (!empty($m['unit'])) <span style="font-size:7.5pt; color:#64748B;">({{ $m['unit'] }})</span>@endif
          </td>
          <td class="r mono">{{ $m['nominal'] ?? '—' }}</td>
          <td class="r mono" style="color:#64748B;">{{ $m['tol_min'] ?? '—' }} … {{ $m['tol_max'] ?? '—' }}</td>
          <td class="r mono font-semibold">{{ $m['min_actual'] ?? '—' }} … {{ $m['max_actual'] ?? '—' }}</td>
          <td class="c mono">{{ $m['sample_n'] }}</td>
          <td class="c">
            <span class="chip {{ $m['pass'] ? 'chip-success' : 'chip-danger' }}">
              {{ $m['pass'] ? 'PASS' : 'FAIL' }}
            </span>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
@endif

<div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:4px; padding:10px 12px; margin-top:16px; font-size:8pt; color:#475569; line-height:1.4;">
  @if (!empty($quality_statement))
    <strong>{{ ($quality_certification ?? '') ?: 'Quality Certification Statement' }}:</strong> {{ $quality_statement }}
  @endif
</div>

<table class="signatures" style="margin-top:28px;">
  <tr>
    <td style="width: 33%;">
      <div class="sig-card">
        <div class="sig-title">QC Inspector</div>
        <div class="sig-line">{{ $inspector_name ?: '—' }}</div>
        <div class="sig-meta">Quality Assurance</div>
      </div>
    </td>
    <td style="width: 33%;">
      <div class="sig-card">
        <div class="sig-title">Quality Manager</div>
        <div class="sig-line">{{ $quality_manager_name ?: '—' }}</div>
        <div class="sig-meta">{{ $quality_manager_role ?: 'Quality Management' }}</div>
      </div>
    </td>
    <td style="width: 34%;">
      <div class="sig-card">
        <div class="sig-title">Customer Acknowledgement</div>
        <div class="sig-line">Received & Inspected</div>
        <div class="sig-meta">Date & Signature</div>
      </div>
    </td>
  </tr>
</table>

@endsection
