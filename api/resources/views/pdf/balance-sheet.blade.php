@extends('pdf._layout')
@section('title', 'Balance Sheet')
@section('watermark', 'CONFIDENTIAL')

@section('content')
  <div class="doc-title">Balance Sheet</div>
  <div class="meta-grid">
    <div class="col">
      <label>As of</label>
      <div class="v">{{ \Carbon\Carbon::parse($data['as_of'])->format('M d, Y') }}</div>
    </div>
    <div class="col" style="text-align:right;">
      <span class="chip">{{ $data['balanced'] ? 'BALANCED' : 'IMBALANCE!' }}</span>
    </div>
  </div>

  <table class="lines">
    <tbody>
      <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">ASSETS</td></tr>
      @foreach ($data['assets']['accounts'] as $r)
        <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ number_format((float) $r['amount'], 2) }}</td></tr>
      @endforeach
      <tr style="font-weight:bold;"><td>Total Assets</td><td class="r">{{ number_format((float) $data['assets']['total'], 2) }}</td></tr>

      <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">LIABILITIES</td></tr>
      @foreach ($data['liabilities']['accounts'] as $r)
        <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ number_format((float) $r['amount'], 2) }}</td></tr>
      @endforeach
      <tr style="font-weight:bold;"><td>Total Liabilities</td><td class="r">{{ number_format((float) $data['liabilities']['total'], 2) }}</td></tr>

      <tr><td colspan="2" style="font-weight:bold;background:#FAFAFA;">EQUITY</td></tr>
      @foreach ($data['equity']['accounts'] as $r)
        <tr><td>&nbsp;&nbsp;{{ $r['code'] }} — {{ $r['name'] }}</td><td class="r">{{ number_format((float) $r['amount'], 2) }}</td></tr>
      @endforeach
      <tr style="font-weight:bold;"><td>Total Equity</td><td class="r">{{ number_format((float) $data['equity']['total'], 2) }}</td></tr>

      <tr style="font-weight:bold;border-top:2px solid #09090B;border-bottom:2px solid #09090B;"><td>Total Liabilities + Equity</td><td class="r">{{ number_format((float) $data['total_liabilities_equity'], 2) }}</td></tr>
    </tbody>
  </table>
@endsection
