@extends('pdf._layout')
@section('title', 'Bill ' . $bill->bill_number)

@section('content')
  <div class="doc-title">Vendor Bill</div>

  <div class="meta-grid">
    <div class="col">
      <label>Vendor</label>
      <div class="v">{{ $bill->vendor->name }}</div>
      @if ($bill->vendor->contact_person) <div>{{ $bill->vendor->contact_person }}</div> @endif
      @if ($bill->vendor->phone)          <div>{{ $bill->vendor->phone }}</div>          @endif
    </div>
    <div class="col">
      <label>Bill No.</label> <div class="v">{{ $bill->bill_number }}</div>
      <label style="margin-top:6px">Date</label> <div>{{ $bill->date->format('M d, Y') }}</div>
      <label style="margin-top:6px">Due Date</label> <div>{{ $bill->due_date->format('M d, Y') }}</div>
      <label style="margin-top:6px">Status</label> <div><span class="chip">{{ $bill->status?->value }}</span></div>
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th style="width:38px">#</th>
        <th>Description</th>
        <th>Account</th>
        <th class="r">Qty</th>
        <th class="r">Unit Price</th>
        <th class="r">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($bill->items as $i => $item)
        <tr>
          <td>{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</td>
          <td>{{ $item->description }}</td>
          <td>{{ $item->expenseAccount?->code }} — {{ $item->expenseAccount?->name }}</td>
          <td class="r">{{ number_format((float) $item->quantity, 2) }}</td>
          <td class="r">{{ number_format((float) $item->unit_price, 2) }}</td>
          <td class="r">{{ number_format((float) $item->total, 2) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="label">Subtotal</td><td class="v">{{ number_format((float) $bill->subtotal, 2) }}</td></tr>
    @if ($bill->is_vatable)
      <tr><td class="label">VAT (12%)</td><td class="v">{{ number_format((float) $bill->vat_amount, 2) }}</td></tr>
    @endif
    <tr class="grand"><td class="label">Total Amount</td><td class="v">PHP {{ number_format((float) $bill->total_amount, 2) }}</td></tr>
    @if ((float) $bill->amount_paid > 0)
      <tr><td class="label">Paid</td><td class="v">{{ number_format((float) $bill->amount_paid, 2) }}</td></tr>
      <tr><td class="label">Balance</td><td class="v">{{ number_format((float) $bill->balance, 2) }}</td></tr>
    @endif
  </table>

  @if ($bill->payments->count() > 0)
    <h3 style="font-size:11px;margin-top:24px;">Payment History</h3>
    <table class="lines">
      <thead>
        <tr>
          <th>Date</th>
          <th>Method</th>
          <th>Reference</th>
          <th>Cash Account</th>
          <th class="r">Amount</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($bill->payments as $p)
          <tr>
            <td>{{ optional($p->payment_date)->format('M d, Y') }}</td>
            <td>{{ $p->payment_method?->label() }}</td>
            <td>{{ $p->reference_number }}</td>
            <td>{{ $p->cashAccount?->code }} — {{ $p->cashAccount?->name }}</td>
            <td class="r">{{ number_format((float) $p->amount, 2) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
@endsection
