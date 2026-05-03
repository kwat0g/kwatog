@extends('pdf._layout')

@section('title', 'Certificate of Conformance')

@section('content')

<h1 class="doc-title">Certificate of Conformance</h1>

<div class="meta-grid">
  <div class="col">
    <label>Certificate No.</label>
    <div class="v">{{ $coc_number }}</div>
    <label style="margin-top:8px;">Issued</label>
    <div class="v">{{ $issued_at }}</div>
  </div>
  <div class="col">
    <label>Inspection No.</label>
    <div class="v">{{ $inspection_number }}</div>
    <label style="margin-top:8px;">Stage</label>
    <div class="v">{{ ucfirst(str_replace('_', '-', $stage)) }}</div>
  </div>
</div>

<table class="lines" style="margin-bottom:12px;">
  <thead>
    <tr>
      <th>Field</th>
      <th>Value</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><strong>Product / Part Number</strong></td>
      <td>{{ $product_part_number }} — {{ $product_name }}</td>
    </tr>
    <tr>
      <td><strong>Batch Quantity</strong></td>
      <td class="r">{{ number_format($batch_quantity) }}</td>
    </tr>
    <tr>
      <td><strong>Sample Size</strong></td>
      <td class="r">{{ number_format($sample_size) }} @if ($aql_code) (AQL Level II 0.65, code {{ $aql_code }}) @endif</td>
    </tr>
    <tr>
      <td><strong>Defects Found / Accept</strong></td>
      <td class="r">{{ $defect_count }} / {{ $accept_count }}</td>
    </tr>
    <tr>
      <td><strong>Inspection Result</strong></td>
      <td><span class="chip" style="background:#DCFCE7; color:#166534;">PASSED</span></td>
    </tr>
    @if (!empty($delivery_number))
      <tr>
        <td><strong>Delivery Note</strong></td>
        <td>{{ $delivery_number }}</td>
      </tr>
    @endif
  </tbody>
</table>

<p style="font-size:11px; line-height:1.6; margin: 16px 0;">
  This certifies that the parts described above have been manufactured and
  inspected in accordance with the customer's drawings and specifications,
  and conform to all applicable quality requirements per IATF 16949
  procedures. Sampling was performed in accordance with ANSI/ASQ Z1.4
  General Inspection Level II at AQL 0.65.
</p>

<div class="signatures">
  <div class="sig">
    <div class="line">QC Inspector — {{ $inspector_name ?? '________________' }}</div>
  </div>
  <div class="sig">
    <div class="line">QC Manager</div>
  </div>
  <div class="sig">
    <div class="line">Customer Acknowledgement</div>
  </div>
</div>

@endsection
