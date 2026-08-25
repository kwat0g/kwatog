@extends('pdf._layout')

@section('title', 'Invoice ' . $invoice->invoice_number)

@section('content')
  <div class="doc-title">Invoice</div>

  <div class="meta-grid">
    <div class="col">
      <label>Customer</label>
      <div class="v">{{ $invoice->customer->name }}</div>
    </div>
    <div class="col">
      <label>Invoice No.</label>
      <div class="v">{{ $invoice->invoice_number }}</div>
      <label>Date</label>
      <div>{{ $invoice->date->format('M d, Y') }}</div>
      <label>Due Date</label>
      <div>{{ $invoice->due_date->format('M d, Y') }}</div>
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th>Description</th>
        <th class="r">Qty</th>
        <th class="r">Unit Price</th>
        <th class="r">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($invoice->items as $item)
        <tr>
          <td>{{ $item->description }}</td>
          <td class="r">{{ number_format((float) $item->quantity, 2) }}</td>
          <td class="r">{{ number_format((float) $item->unit_price, 2) }}</td>
          <td class="r">{{ number_format((float) $item->total, 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="label">Subtotal</td><td class="v">{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
    <tr><td class="label">VAT</td><td class="v">{{ number_format((float) $invoice->vat_amount, 2) }}</td></tr>
    <tr class="grand"><td class="label">Total Due</td><td class="v">PHP {{ number_format((float) $invoice->total_amount, 2) }}</td></tr>
    <tr><td class="label">Paid</td><td class="v">{{ number_format((float) $invoice->amount_paid, 2) }}</td></tr>
    <tr><td class="label">Balance</td><td class="v">{{ number_format((float) $invoice->balance, 2) }}</td></tr>
  </table>

  <p style="margin-top:24px;font-size:9px;color:#555;">
    This customer copy contains the invoice and payment summary only.
  </p>
@endsection
