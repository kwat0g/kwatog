<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>@yield('title', 'Document')</title>
<style>
  /* DomPDF: DejaVu Sans is the only font we ship; Geist is SPA-only.
     Numbers use the same family — alignment is okay because all DOM tables
     align via CSS text-align rather than tabular figures. */
  * { box-sizing: border-box; }
  html, body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #09090B; margin: 0; padding: 0; }
  .header { display: table; width: 100%; border-bottom: 1px solid #999; padding: 8px 0; margin-bottom: 12px; }
  .header .left  { display: table-cell; vertical-align: top; }
  .header .right { display: table-cell; vertical-align: top; text-align: right; font-size: 9px; color: #555; }
  .header h1 { font-size: 14px; margin: 0 0 2px; }
  .header .addr { font-size: 9px; color: #555; }
  .doc-title { font-size: 16px; margin: 0 0 4px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; }
  .meta-grid { display: table; width: 100%; margin-bottom: 12px; }
  .meta-grid .col { display: table-cell; vertical-align: top; width: 50%; }
  .meta-grid label { display: block; font-size: 8px; color: #777; text-transform: uppercase; letter-spacing: 0.06em; }
  .meta-grid .v { font-weight: bold; }
  table.lines { width: 100%; border-collapse: collapse; }
  table.lines th { background: #F4F4F5; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em; color: #555; border-bottom: 1px solid #999; }
  table.lines td { padding: 6px 8px; border-bottom: 1px solid #E4E4E7; vertical-align: top; }
  table.lines td.r, table.lines th.r { text-align: right; }
  .totals { width: 50%; margin-left: 50%; margin-top: 8px; }
  .totals tr td { padding: 4px 8px; border-bottom: 1px solid #E4E4E7; }
  .totals tr td.label { color: #555; }
  .totals tr td.v { text-align: right; }
  .totals tr.grand td { border-top: 2px solid #09090B; border-bottom: 2px solid #09090B; font-weight: bold; }
  .footer { position: fixed; bottom: 12px; left: 0; right: 0; text-align: center; font-size: 8px; color: #888; }
  .watermark { position: fixed; top: 40%; left: 12%; right: 12%; font-size: 80px; color: #F4F4F5; transform: rotate(-30deg); text-align: center; font-weight: bold; letter-spacing: 8px; z-index: -1; }
  .signatures { display: table; width: 100%; margin-top: 32px; }
  .sig { display: table-cell; width: 33%; padding: 0 12px; vertical-align: top; }
  .sig .line { border-top: 1px solid #555; padding-top: 4px; font-size: 9px; color: #555; text-align: center; }
  .chip { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.04em; background: #F4F4F5; color: #555; }
</style>
</head>
<body>

@hasSection('watermark') <div class="watermark">@yield('watermark')</div> @endif

<div class="header">
  <div class="left">
    <h1>{{ $company['name'] }}</h1>
    <div class="addr">{{ $company['address'] ?? '' }}</div>
    @if (!empty($company['tin']))
      <div class="addr">TIN: {{ $company['tin'] }}</div>
    @endif
  </div>
  <div class="right">
    Generated: {{ now()->format('M d, Y H:i') }}<br>
    By: {{ $user ?? 'system' }}
  </div>
</div>

@yield('content')

<div class="footer">
  Page <span class="pagenum"></span> · {{ $company['name'] }} · Confidential — for internal use
</div>

<script type="text/php">
  if (isset($pdf)) {
    $font = $fontMetrics->getFont('DejaVu Sans');
    $size = 8;
    $pdf->page_text(280, 820, "Page {PAGE_NUM} of {PAGE_COUNT}", $font, $size, [0.4, 0.4, 0.4]);
  }
</script>

</body>
</html>
