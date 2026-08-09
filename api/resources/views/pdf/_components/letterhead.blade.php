{{--
    Series E (E1) shared letterhead component — executive corporate layout.
    Reads from `$company` (injected by PdfRenderService).
--}}
<div class="brand-bar"></div>
<table class="header-table">
  <tr>
    <td style="width: 58%;">
      @if (!empty($company['name']))
        <div class="company-title">{{ $company['name'] }}</div>
      @endif
      <div class="company-sub">
        @if (!empty($company['address']))
          {{ $company['address'] }}<br>
        @endif
        @if (!empty($company['phone']) || !empty($company['email']))
          @if (!empty($company['phone'])) Tel: {{ $company['phone'] }} @endif
          @if (!empty($company['phone']) && !empty($company['email'])) &middot; @endif
          @if (!empty($company['email'])) {{ $company['email'] }} @endif
          <br>
        @endif
        @if (!empty($company['tin']))
          TIN: {{ $company['tin'] }}
          @if (!empty($company['vat_status'])) &middot; {{ $company['vat_status'] }} @endif
          &middot;
        @endif
        @if (!empty($company['certification']))
          <span style="font-weight:600; color:#475569;">{{ $company['certification'] }}</span>
        @endif
      </div>
    </td>
    <td style="width: 42%;" class="doc-badge-box">
      @if (!empty($docTitle))
        <div class="doc-title">{{ $docTitle }}</div>
      @endif
      <div class="doc-meta">
        Issued: <span class="val">{{ $generated['at_text'] ?? now()->format('M d, Y H:i') }}</span><br>
        Issued By: <span class="val">{{ $generated['by'] ?? 'System' }}</span>
      </div>
    </td>
  </tr>
</table>
