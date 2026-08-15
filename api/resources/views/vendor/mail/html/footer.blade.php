@php($brand = app(\App\Common\Services\EmailBrandingService::class)->data())
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto; text-align: center;">
<tr>
<td class="content-cell" align="center" style="padding: 26px 32px 36px; color: #667085; font-size: 12px; line-height: 1.65;">
<div style="height: 1px; margin: 0 auto 22px; background-color: #e4e7ec; font-size: 0; line-height: 0;">&nbsp;</div>
<div style="color: #344054; font-size: 13px; font-weight: 800;">{{ $brand['name'] }}</div>
<div style="margin-top: 2px;">{{ $brand['legal_name'] }}</div>
<div style="margin-top: 2px;">{{ $brand['address'] }}</div>
<div>
@if ($brand['phone']) <a href="tel:{{ $brand['phone'] }}" style="color: #667085; text-decoration: none;">{{ $brand['phone'] }}</a> @endif
@if ($brand['phone'] && $brand['email']) <span style="padding: 0 4px;">·</span> @endif
@if ($brand['email']) <a href="mailto:{{ $brand['email'] }}" style="color: #667085; text-decoration: none;">{{ $brand['email'] }}</a> @endif
</div>
@if ($brand['certification'])
<div style="margin-top: 8px; color: #98a2b3;">{{ $brand['certification'] }}</div>
@endif
<div style="margin-top: 12px; color: #98a2b3;">This is an automated message. Please do not reply unless instructed.</div>
<div style="margin-top: 8px; color: #98a2b3;">© {{ date('Y') }} {{ $brand['legal_name'] }}. All rights reserved.</div>
</td>
</tr>
</table>
</td>
</tr>
