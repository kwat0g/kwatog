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
  </div>

  <table class="lines">
    <tbody>
      <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">REVENUE</td></tr>
      @foreach ($data['revenue']['accounts'] as $r)
        <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ number_format((float) $r['amount'], 2) }}</td></tr>
      @endforeach
      <tr style="font-weight:bold;"><td>Total Revenue</td><td class="r">{{ number_format((float) $data['revenue']['total'], 2) }}</td></tr>

      @if (count($data['cogs']['accounts']) > 0)
        <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">COST OF GOODS SOLD</td></tr>
        @foreach ($data['cogs']['accounts'] as $r)
          <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ number_format((float) $r['amount'], 2) }}</td></tr>
        @endforeach
        <tr style="font-weight:bold;"><td>Total COGS</td><td class="r">{{ number_format((float) $data['cogs']['total'], 2) }}</td></tr>
      @endif

      <tr style="font-weight:bold;border-top:2px solid #09090B;"><td>GROSS PROFIT</td><td class="r">{{ number_format((float) $data['gross_profit'], 2) }}</td></tr>

      <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">OPERATING EXPENSES</td></tr>
      @foreach ($data['operating_expenses']['accounts'] as $r)
        <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ number_format((float) $r['amount'], 2) }}</td></tr>
      @endforeach
      <tr style="font-weight:bold;"><td>Total Operating Expenses</td><td class="r">{{ number_format((float) $data['operating_expenses']['total'], 2) }}</td></tr>

      <tr style="font-weight:bold;border-top:2px solid #09090B;border-bottom:2px solid #09090B;"><td>NET INCOME</td><td class="r">PHP {{ number_format((float) $data['net_income'], 2) }}</td></tr>
    </tbody>
  </table>
@endsection
