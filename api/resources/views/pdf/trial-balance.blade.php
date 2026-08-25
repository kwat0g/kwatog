@extends('pdf._layout')
@section('title', 'Trial Balance')

@section('content')
  <div class="doc-title">Trial Balance</div>
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
    <thead>
      <tr>
        <th>Code</th>
        <th>Account</th>
        <th>Type</th>
        <th class="r">Debit</th>
        <th class="r">Credit</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($data['accounts'] as $a)
        <tr>
          <td>{{ $a['code'] }}</td>
          <td>{{ $a['name'] }}</td>
          <td>{{ $a['type'] }}</td>
          <td class="r">{{ $currency }} {{ $money->format($a['debit_total']) }}</td>
          <td class="r">{{ $currency }} {{ $money->format($a['credit_total']) }}</td>
        </tr>
      @endforeach
      <tr style="font-weight:bold;border-top:2px solid #09090B;">
        <td colspan="3" class="r">Totals</td>
        <td class="r">{{ $currency }} {{ $money->format($data['totals']['debit']) }}</td>
        <td class="r">{{ $currency }} {{ $money->format($data['totals']['credit']) }}</td>
      </tr>
    </tbody>
  </table>
@endsection
