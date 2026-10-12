{{--
    Phase 2 (PDF foundation) — Atelier chrome for every PDF.
    Warm paper canvas, espresso ink, one clay accent. Hierarchy from borders,
    never shadows. Chips stay readable in black & white (label + border carry
    meaning, never colour alone).

    Variables provided by App\Common\Services\Pdf\PdfRenderService:
      - $company       (array — name/address/phone/email/tin/vat_status/logo_path/
                        public_url/certification/disclaimer + accent/logo_data_uri/
                        tagline/terms)
      - $brand         (array — accent/logo_data_uri/tagline/terms/name)
      - $generated     (array — by/by_user/at/at_text)
      - $confidential  (bool)
      - $watermark     (string|null)
      - $docTitle      (string|null)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>@yield('title', $docTitle ?? 'Document')</title>
<style>
  @page {
    margin: 15mm 15mm 20mm 15mm;
  }
  * { box-sizing: border-box; }
  html, body {
    /* Core Helvetica/Courier are WinAnsi and have no ₱ glyph; dompdf falls
       back per glyph to the DejaVu faces, so ₱ stops printing as "?".
       Weights must be the keywords bold/normal: dompdf only maps 400/700,
       so 600 or 800 finds no face, drops to Times and loses the fallback. */
    font-family: 'Helvetica Neue', 'Helvetica', Arial, 'DejaVu Sans', sans-serif;
    font-size: 9pt;
    line-height: 1.5;
    color: #1f1b16; /* Atelier espresso ink */
    margin: 0;
    padding: 0;
    background: #FFFFFF;
  }
  .mono, .num { font-family: 'Courier New', Courier, 'DejaVu Sans Mono', monospace; font-variant-numeric: tabular-nums; }
  table.lines td.r, table.lines th.r, .totals-box td.val { font-variant-numeric: tabular-nums; }

  /* Brand Header & Letterhead */
  .brand-bar {
    width: 100%;
    height: 5px;
    background: {{ $company['accent'] ?? '#b4542a' }};
    border-radius: 3px 3px 0 0;
    margin-bottom: 14px;
  }

  .header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
    border-bottom: 1px solid #e8e2d8;
    padding-bottom: 12px;
  }
  .header-table td { vertical-align: middle; }

  .company-title {
    font-size: 15pt;
    font-weight: bold;
    color: #1f1b16;
    letter-spacing: -0.5px;
    margin: 0 0 2px;
    text-transform: uppercase;
  }
  .company-tagline {
    font-size: 8pt;
    font-style: italic;
    color: #6b6259;
    margin-bottom: 2px;
  }
  .company-sub {
    font-size: 8pt;
    color: #6b6259;
    line-height: 1.4;
  }
  .company-logo { max-height: 44px; max-width: 180px; margin-bottom: 4px; }

  .doc-badge-box {
    text-align: right;
  }

  .doc-title {
    font-size: 19pt;
    font-weight: bold;
    color: #1f1b16;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 4px;
  }

  .doc-meta {
    font-size: 8.5pt;
    color: #6b6259;
    line-height: 1.4;
  }
  .doc-meta .val {
    font-weight: bold;
    color: #1f1b16;
  }

  /* Document head: number block + status + references */
  .doc-head {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 16px;
  }
  .doc-head td { vertical-align: top; padding: 0; }
  .doc-number {
    font-size: 13pt;
    font-weight: bold;
    color: #1f1b16;
  }
  .doc-refs { font-size: 8pt; color: #6b6259; margin-top: 2px; }
  .doc-refs .val { font-weight: bold; color: #4a4239; }

  /* Controlled-document block (IATF forms, CoC, 8D) */
  .control-block {
    border: 1px solid #e8e2d8;
    background: #f7f4ef;
    border-radius: 6px;
    padding: 8px 12px;
    margin-bottom: 16px;
    font-size: 8pt;
    color: #4a4239;
  }
  .control-block .lbl {
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b6259;
  }

  /* Meta Grid (Two-column info headers) */
  .meta-grid {
    width: 100%;
    display: table;
    table-layout: fixed;
    margin-bottom: 20px;
  }
  .meta-grid .col {
    display: table-cell;
    vertical-align: top;
    padding-right: 20px;
  }
  .meta-grid .col:last-child {
    padding-right: 0;
  }
  .meta-grid label,
  .meta-grid .label {
    display: block;
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b6259;
    margin-bottom: 2px;
  }
  .meta-grid .v {
    font-size: 9.5pt;
    font-weight: bold;
    color: #1f1b16;
    margin-bottom: 6px;
  }

  /* Info Grid (Alternative layout) */
  .info-grid {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 18px;
  }
  .info-grid td {
    width: 50%;
    vertical-align: top;
    padding: 0;
  }
  .info-card {
    background: #f7f4ef;
    border: 1px solid #e8e2d8;
    border-radius: 6px;
    padding: 12px 14px;
    margin-right: 8px;
  }
  .info-card.last {
    margin-right: 0;
    margin-left: 8px;
  }
  .info-card-title {
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #6b6259;
    border-bottom: 1px solid #e8e2d8;
    padding-bottom: 4px;
    margin-bottom: 8px;
  }
  .info-row {
    margin-bottom: 4px;
    font-size: 8.5pt;
  }
  .info-row .lbl {
    color: #6b6259;
    display: inline-block;
    width: 90px;
    font-size: 8pt;
  }
  .info-row .val {
    color: #1f1b16;
    font-weight: bold;
  }

  /* Line Items Table */
  table.lines {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    margin-bottom: 18px;
  }
  table.lines thead { display: table-header-group; }
  table.lines tr { page-break-inside: avoid; }
  table.lines th {
    background: #1f1b16; /* espresso, not slate */
    color: #fdfcfa;
    font-size: 8pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 8px 10px;
    text-align: left;
    border: none;
  }
  table.lines th:first-child { border-top-left-radius: 4px; border-bottom-left-radius: 4px; }

  table.lines td {
    padding: 8px 10px;
    border-bottom: 1px solid #f0eae0;
    font-size: 9pt;
    vertical-align: top;
    color: #1f1b16;
  }
  table.lines tr:nth-child(even) td { background: #f7f4ef; }
  table.lines tr:last-child td {
    border-bottom: 1px solid #e8e2d8;
  }
  table.lines td.r, table.lines th.r { text-align: right; }
  table.lines td.c, table.lines th.c { text-align: center; }

  /* Summary & Totals */
  table.totals,
  .totals-box {
    width: 320px;
    margin-left: auto;
    border-collapse: collapse;
    margin-top: 10px;
    margin-bottom: 18px;
    page-break-inside: avoid;
  }
  table.totals td,
  .totals-box td {
    padding: 6px 10px;
    font-size: 9pt;
  }
  table.totals td.label,
  table.totals td.lbl,
  .totals-box td.lbl {
    color: #6b6259;
    text-align: right;
  }
  table.totals td.v,
  table.totals td.val,
  .totals-box td.val {
    text-align: right;
    font-weight: bold;
    color: #1f1b16;
  }
  table.totals tr.grand td,
  .totals-box tr.grand-total td {
    border-top: 2px solid #1f1b16;
    background: #f7f4ef;
    font-size: 11pt;
    font-weight: bold;
    color: #1f1b16;
    padding: 10px;
  }
  table.totals tr.grand td:first-child,
  .totals-box tr.grand-total td:first-child {
    border-top-left-radius: 0;
    border-bottom-left-radius: 4px;
  }
  table.totals tr.grand td:last-child,
  .totals-box tr.grand-total td:last-child {
    border-top-right-radius: 0;
    border-bottom-right-radius: 4px;
  }
  .amount-words {
    font-size: 8pt;
    font-style: italic;
    color: #4a4239;
    text-align: right;
    margin-top: 2px;
  }

  /* Notes / terms */
  .notes-block {
    border: 1px solid #e8e2d8;
    border-radius: 6px;
    padding: 10px 12px;
    margin-bottom: 16px;
    font-size: 8.5pt;
    color: #4a4239;
    page-break-inside: avoid;
  }
  .notes-block .notes-title {
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.8px;
    color: #6b6259;
    margin-bottom: 4px;
  }

  /* Report kit: filter summary, KPI strip, empty state */
  .filter-summary {
    border: 1px solid #e8e2d8;
    background: #f7f4ef;
    border-radius: 6px;
    padding: 8px 12px;
    margin-bottom: 14px;
    font-size: 8.5pt;
    color: #4a4239;
  }
  .filter-summary .count {
    font-weight: bold;
    color: #1f1b16;
  }
  .kpi-strip {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
  }
  .kpi-strip td {
    border: 1px solid #e8e2d8;
    border-radius: 6px;
    padding: 8px 10px;
    text-align: center;
    width: 25%;
  }
  .kpi-strip .kpi-val {
    font-size: 12pt;
    font-weight: bold;
    color: #1f1b16;
  }
  .kpi-strip .kpi-lbl {
    font-size: 7.5pt;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b6259;
  }
  .empty-state {
    border: 1px dashed #e8e2d8;
    border-radius: 6px;
    padding: 24px;
    text-align: center;
    font-size: 9pt;
    color: #6b6259;
    margin-bottom: 16px;
  }

  /* Status Chips — label + border carry meaning in B&W, never colour alone */
  .chip {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: #f5f1e9;
    color: #4a4239;
    border: 1px solid #e8e2d8;
  }
  .chip-success { background: #e3ece7; color: #2c4e3b; border-color: #3f6d54; }
  .chip-warning { background: #f7eedc; color: #7a5314; border-color: #b07a22; }
  .chip-danger  { background: #f6e4e1; color: #7a2820; border-color: #a8392f; }
  .chip-info    { background: #e3e9f0; color: #2a4059; border-color: #3d5a80; }

  /* Signatures */
  .signatures {
    width: 100%;
    display: table;
    table-layout: fixed;
    margin-top: 28px;
    page-break-inside: avoid;
  }
  .signatures .sig,
  .signatures td {
    display: table-cell;
    vertical-align: bottom;
    padding: 0 15px;
    text-align: center;
  }
  .sig-card {
    background: #ffffff;
    border-radius: 6px;
    padding: 10px;
  }
  .sig-title {
    font-size: 7.5pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b6259;
    margin-bottom: 30px;
  }
  .sig-line,
  .signatures .line {
    border-top: 1px solid #1f1b16;
    padding-top: 6px;
    font-size: 8.5pt;
    font-weight: bold;
    color: #1f1b16;
  }
  .sig-meta {
    font-size: 7.5pt;
    color: #6b6259;
    margin-top: 2px;
  }

  /* Utilities */
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .font-bold { font-weight: bold; }
  .text-muted { color: #6b6259; }
  .page-break { page-break-after: always; }

</style>
</head>
<body>

@include('pdf._components.watermark')

@include('pdf._components.letterhead')

@yield('content')

@include('pdf._components.footer')

</body>
</html>
