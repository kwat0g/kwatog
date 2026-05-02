@extends('pdf._layout')
@section('title', 'JE ' . $je->entry_number)

@section('content')
  <div class="doc-title">Journal Entry</div>

  <div class="meta-grid">
    <div class="col">
      <label>Entry No.</label> <div class="v">{{ $je->entry_number }}</div>
      <label style="margin-top:6px">Date</label> <div>{{ $je->date->format('M d, Y') }}</div>
      <label style="margin-top:6px">Status</label> <div><span class="chip">{{ $je->status?->value }}</span></div>
    </div>
    <div class="col">
      <label>Description</label>
      <div>{{ $je->description }}</div>
      @if ($je->reference_type)
        <label style="margin-top:6px">Reference</label>
        <div>{{ $je->referenceLabel() }}</div>
      @endif
    </div>
  </div>

  <table class="lines">
    <thead>
      <tr>
        <th style="width:38px">#</th>
        <th>Account</th>
        <th>Description</th>
        <th class="r">Debit</th>
        <th class="r">Credit</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($je->lines as $line)
        <tr>
          <td>{{ $line->line_no }}</td>
          <td>{{ $line->account?->code }} — {{ $line->account?->name }}</td>
          <td>{{ $line->description }}</td>
          <td class="r">{{ (float) $line->debit  > 0 ? number_format((float) $line->debit,  2) : '' }}</td>
          <td class="r">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '' }}</td>
        </tr>
      @endforeach
      <tr style="font-weight:bold;border-top:2px solid #09090B;">
        <td colspan="3" class="r">Totals</td>
        <td class="r">{{ number_format((float) $je->total_debit, 2) }}</td>
        <td class="r">{{ number_format((float) $je->total_credit, 2) }}</td>
      </tr>
    </tbody>
  </table>

  <div class="signatures">
    <div class="sig"><div style="height:32px"></div><div class="line">Prepared by<br>{{ $je->creator?->name ?? '' }}</div></div>
    <div class="sig"><div style="height:32px"></div><div class="line">Posted by<br>{{ $je->poster?->name ?? '' }}</div></div>
    <div class="sig"><div style="height:32px"></div><div class="line">Date</div></div>
  </div>
@endsection
