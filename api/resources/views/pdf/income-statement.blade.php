@extends('pdf._layout')
@section('title', 'Income Statement')
@section('watermark', 'CONFIDENTIAL')

@section('content')
  <div class="doc-title">Income Statement</div>
  <div class="meta-grid">
    <div class="col">
      <label>Period</label>
      <div class="v">{{ \Carbon\Carbon::parse($data['from'])->format('M d, Y') }} — {{ \Carbon\Carbon::parse($data['to'])->format('M d, Y') }}</div>
    </div>
    <div class="col" style="text-align:right;">
      <label>Currency</label>
      <div class="v">{{ $currency }}</div>
    </div>
  </div>

  <table class="lines">
    <tbody>
      <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">REVENUE</td></tr>
      @foreach ($data['revenue']['accounts'] as $r)
        <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ $currency }} {{ $money->format($r['amount']) }}</td></tr>
      @endforeach
      <tr style="font-weight:bold;"><td>Total Revenue</td><td class="r">{{ $currency }} {{ $money->format($data['revenue']['total']) }}</td></tr>

      @if (count($data['cogs']['accounts']) > 0)
        <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">COST OF GOODS SOLD</td></tr>
        @foreach ($data['cogs']['accounts'] as $r)
          <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ $currency }} {{ $money->format($r['amount']) }}</td></tr>
        @endforeach
        <tr style="font-weight:bold;"><td>Total COGS</td><td class="r">{{ $currency }} {{ $money->format($data['cogs']['total']) }}</td></tr>
      @endif

      <tr style="font-weight:bold;border-top:2px solid #09090B;"><td>GROSS PROFIT</td><td class="r">{{ $currency }} {{ $money->format($data['gross_profit']) }}</td></tr>

      <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">OPERATING EXPENSES</td></tr>
      @foreach ($data['operating_expenses']['accounts'] as $r)
        <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ $currency }} {{ $money->format($r['amount']) }}</td></tr>
      @endforeach
      <tr style="font-weight:bold;"><td>Total Operating Expenses</td><td class="r">{{ $currency }} {{ $money->format($data['operating_expenses']['total']) }}</td></tr>

      <tr style="font-weight:bold;border-top:2px solid #09090B;border-bottom:2px solid #09090B;"><td>NET INCOME</td><td class="r">{{ $currency }} {{ $money->format($data['net_income']) }}</td></tr>
    </tbody>
  </table>
@endsection
