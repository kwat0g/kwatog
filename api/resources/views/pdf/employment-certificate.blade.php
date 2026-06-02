@extends('pdf._layout')

@section('title', 'Certificate of Employment')

@section('content')

<h1 class="doc-title">Certificate of Employment</h1>

<p style="font-size:11px; line-height:1.7; margin: 20px 0 12px;">TO WHOM IT MAY CONCERN:</p>

<p style="font-size:11px; line-height:1.8; margin: 0 0 12px; text-align: justify;">
  This is to certify that <strong>{{ $employee_name }}</strong>, bearing Employee No.
  <strong>{{ $employee_no }}</strong>, is a bona fide employee of
  {{ $company['name'] ?? 'Philippine Ogami Corporation' }}, holding the position of
  <strong>{{ $position }}</strong> in the {{ $department }} Department since
  <strong>{{ $date_hired }}</strong>, with employment status of
  <strong>{{ $employment_status }}</strong>.
</p>

@if (!empty($show_salary) && !empty($salary_text))
  <p style="font-size:11px; line-height:1.8; margin: 0 0 12px; text-align: justify;">
    His/Her current {{ $salary_basis }} is <strong>{{ $salary_text }}</strong>.
  </p>
@endif

<p style="font-size:11px; line-height:1.8; margin: 0 0 12px; text-align: justify;">
  This certification is issued upon the employee's request for whatever legal
  purpose it may serve.
</p>

<p style="font-size:11px; line-height:1.8; margin: 16px 0 0;">
  Issued this {{ $issued_day }} day of {{ $issued_month_year }}.
</p>

<div class="signatures" style="margin-top:56px;">
  <div class="sig">
    <div class="line">{{ $hr_signatory ?? 'HR Officer / HR Manager' }}</div>
  </div>
  <div class="sig">
    <div class="line">{{ $company['name'] ?? 'Philippine Ogami Corporation' }}</div>
  </div>
</div>

@endsection
