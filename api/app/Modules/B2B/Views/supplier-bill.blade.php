@extends('pdf._layout')

@section('title', 'Bill ' . $bill->bill_number)

@section('content')
<div class="doc-title">Supplier invoice</div>
<div class="meta-grid">
  <div class="col"><label>Supplier</label><div class="v">{{ $bill->vendor?->name ?? '—' }}</div></div>
  <div class="col"><label>Bill number</label><div class="v mono">{{ $bill->bill_number }}</div><label>Date</label><div>{{ optional($bill->date)->format('M d, Y') ?? '—' }}</div></div>
  <div class="col"><label>Due date</label><div class="v">{{ optional($bill->due_date)->format('M d, Y') ?? '—' }}</div><label>Status</label><div>{{ $bill->status?->label() ?? (string) $bill->status }}</div></div>
</div>
<table class="lines">
  <thead><tr><th>#</th><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Total</th></tr></thead>
  <tbody>
    @foreach ($lines as $index => $line)
      <tr><td>{{ $index + 1 }}</td><td>{{ $line['description'] }}</td><td class="r mono">{{ $line['quantity'] }} {{ $line['unit'] }}</td><td class="r mono">PHP {{ $line['unit_price'] }}</td><td class="r mono">PHP {{ $line['total'] }}</td></tr>
    @endforeach
  </tbody>
</table>
<table class="totals">
  <tr><td class="label">Subtotal</td><td class="v">PHP {{ $subtotal }}</td></tr>
  @if ($bill->is_vatable)<tr><td class="label">VAT</td><td class="v">PHP {{ $vat_amount }}</td></tr>@endif
  <tr class="grand"><td class="label">Total amount</td><td class="v">PHP {{ $total_amount }}</td></tr>
  <tr><td class="label">Paid</td><td class="v">PHP {{ $amount_paid }}</td></tr>
  <tr><td class="label">Balance</td><td class="v">PHP {{ $balance }}</td></tr>
</table>
@if (count($payments) > 0)
  <h3 style="font-size:11px;margin-top:24px;">Payment history</h3>
  <table class="lines"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="r">Amount</th></tr></thead><tbody>
    @foreach ($payments as $payment)<tr><td>{{ $payment['date'] ?? '—' }}</td><td>{{ $payment['method'] }}</td><td>{{ $payment['reference'] ?? '—' }}</td><td class="r mono">PHP {{ $payment['amount'] }}</td></tr>@endforeach
  </tbody></table>
@endif
@endsection
