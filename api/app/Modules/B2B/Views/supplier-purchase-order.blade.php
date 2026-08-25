@extends('pdf._layout')

@section('title', 'Purchase Order ' . $po->po_number)

@section('content')
<div class="doc-title">Purchase Order</div>
<div class="meta-grid">
  <div class="col">
    <label>Supplier</label>
    <div class="v">{{ $po->vendor?->name ?? '—' }}</div>
    @if ($po->vendor?->address)<div>{{ $po->vendor->address }}</div>@endif
  </div>
  <div class="col">
    <label>PO number</label><div class="v mono">{{ $po->po_number }}</div>
    <label>Date</label><div>{{ optional($po->date)->format('M d, Y') ?? '—' }}</div>
    <label>Expected delivery</label><div>{{ optional($po->expected_delivery_date)->format('M d, Y') ?? '—' }}</div>
  </div>
  <div class="col">
    <label>Status</label><div class="v">{{ str_replace('_', ' ', ucfirst((string) ($po->status?->value ?? $po->status))) }}</div>
    <label>Incoterm</label><div>{{ $po->incoterm?->value ?? '—' }}</div>
  </div>
</div>
<table class="lines">
  <thead><tr><th>#</th><th>Item code</th><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Total</th></tr></thead>
  <tbody>
    @foreach ($lines as $index => $line)
      <tr><td>{{ $index + 1 }}</td><td class="mono">{{ $line['code'] }}</td><td>{{ $line['name'] }}</td><td class="r mono">{{ $line['quantity'] }} {{ $line['unit'] }}</td><td class="r mono">PHP {{ $line['unit_price'] }}</td><td class="r mono">PHP {{ $line['total'] }}</td></tr>
    @endforeach
  </tbody>
</table>
<table class="totals">
  <tr><td class="label">Subtotal</td><td class="v">PHP {{ $subtotal }}</td></tr>
  @if ($po->is_vatable)<tr><td class="label">VAT</td><td class="v">PHP {{ $vat_amount }}</td></tr>@endif
  <tr class="grand"><td class="label">Total amount</td><td class="v">PHP {{ $total_amount }}</td></tr>
</table>
@endsection
