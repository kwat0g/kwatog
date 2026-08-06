@extends('pdf._layout')

@section('title', 'Purchase Order ' . $po->po_number)

@section('content')
<table class="info-grid">
  <tr>
    <td>
      <div class="info-card">
        <div class="info-card-title">Vendor / Supplier</div>
        <div style="font-weight:700; font-size:10pt; color:#0F172A; margin-bottom:2px;">{{ $po->vendor?->name ?? '—' }}</div>
        @if ($po->vendor?->address)
          <div style="font-size:8pt; color:#475569; margin-bottom:4px;">{{ $po->vendor->address }}</div>
        @endif
        @if ($po->vendor?->contact_person || $po->vendor?->email || $po->vendor?->phone)
          <div style="font-size:8pt; color:#64748B;">
            @if ($po->vendor->contact_person) Attn: {{ $po->vendor->contact_person }} &middot; @endif
            @if ($po->vendor->email) {{ $po->vendor->email }} @endif
            @if ($po->vendor->phone) &middot; {{ $po->vendor->phone }} @endif
          </div>
        @endif
      </div>
    </td>
    <td>
      <div class="info-card last">
        <div class="info-card-title">Order Summary</div>
        <div class="info-row"><span class="lbl">PO Number:</span> <span class="val mono" style="font-weight:700;">{{ $po->po_number }}</span></div>
        <div class="info-row"><span class="lbl">Order Date:</span> <span class="val mono">{{ optional($po->date)->format('M d, Y') ?? '—' }}</span></div>
        <div class="info-row"><span class="lbl">Expected Date:</span> <span class="val mono">{{ optional($po->expected_delivery_date)->format('M d, Y') ?? '—' }}</span></div>
        <div class="info-row">
          <span class="lbl">Status:</span>
          <span class="chip chip-info">{{ str_replace('_', ' ', strtoupper((string) ($po->status?->value ?? $po->status))) }}</span>
        </div>
      </div>
    </td>
  </tr>
</table>

<table class="lines">
  <thead>
    <tr>
      <th style="width: 32px;" class="c">#</th>
      <th style="width: 110px;">Item Code</th>
      <th>Description</th>
      <th style="width: 80px;" class="r">Qty</th>
      <th style="width: 95px;" class="r">Unit Price</th>
      <th style="width: 100px;" class="r">Total (PHP)</th>
    </tr>
  </thead>
  <tbody>
    @foreach($po->items as $i => $line)
      <tr>
        <td class="c mono" style="color:#64748B;">{{ $i + 1 }}</td>
        <td class="mono" style="font-weight:600;">{{ $line->item?->code ?? '—' }}</td>
        <td>
          <div style="font-weight:600; color:#0F172A;">{{ $line->item?->name ?? $line->description }}</div>
          @if ($line->description && $line->item?->name && $line->description !== $line->item->name)
            <div style="font-size:7.5pt; color:#64748B;">{{ $line->description }}</div>
          @endif
        </td>
        <td class="r mono">{{ number_format((float) $line->quantity, 2) }} {{ $line->unit }}</td>
        <td class="r mono">₱ {{ number_format((float) $line->unit_price, 2) }}</td>
        <td class="r mono" style="font-weight:600;">₱ {{ number_format((float) $line->total, 2) }}</td>
      </tr>
    @endforeach
  </tbody>
</table>

<div class="totals-container">
  <table class="totals-box">
    <tr>
      <td class="lbl">Subtotal:</td>
      <td class="val mono">₱ {{ number_format((float) $po->subtotal, 2) }}</td>
    </tr>
    @if($po->is_vatable)
      <tr>
        <td class="lbl">VAT (12%):</td>
        <td class="val mono">₱ {{ number_format((float) $po->vat_amount, 2) }}</td>
      </tr>
    @endif
    <tr class="grand-total">
      <td class="lbl">Total Amount:</td>
      <td class="val mono">₱ {{ number_format((float) $po->total_amount, 2) }}</td>
    </tr>
  </table>
</div>

@if (!empty($approvals))
  @include('pdf._components.approval_signatures', ['approvals' => $approvals])
@endif
@endsection
