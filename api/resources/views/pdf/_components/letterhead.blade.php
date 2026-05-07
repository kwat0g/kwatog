{{--
    Series E (E1) shared letterhead. Reads from `$company` (injected by
    PdfRenderService). Per-document Blades just `@include` this and focus
    on body content.
--}}
<div class="header">
  <div class="left">
    <h1 style="margin:0; font-size:14px;">{{ $company['name'] ?? 'Philippine Ogami Corporation' }}</h1>
    @if (!empty($company['address']))
      <div style="font-size:9px; color:#555;">{{ $company['address'] }}</div>
    @endif
    @if (!empty($company['phone']) || !empty($company['email']))
      <div style="font-size:9px; color:#555;">
        @if (!empty($company['phone'])) Tel: {{ $company['phone'] }} @endif
        @if (!empty($company['phone']) && !empty($company['email'])) &middot; @endif
        @if (!empty($company['email'])) {{ $company['email'] }} @endif
      </div>
    @endif
    @if (!empty($company['tin']))
      <div style="font-size:9px; color:#555;">
        TIN: {{ $company['tin'] }}
        @if (!empty($company['vat_status'])) &middot; {{ $company['vat_status'] }} @endif
      </div>
    @endif
  </div>
  <div class="right" style="text-align:right; font-size:9px; color:#555;">
    @if (!empty($docTitle))
      <div style="font-size:13px; font-weight:bold; color:#09090B; text-transform:uppercase; letter-spacing:0.5px;">
        {{ $docTitle }}
      </div>
    @endif
    Generated: {{ $generated['at_text'] ?? now()->format('M d, Y H:i') }}<br>
    By: {{ $generated['by'] ?? 'system' }}
  </div>
</div>
