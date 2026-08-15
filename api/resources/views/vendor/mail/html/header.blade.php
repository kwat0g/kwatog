@props(['url'])
@php($brand = app(\App\Common\Services\EmailBrandingService::class)->data())
@php($logoCid = $brand['logo_path'] ? 'cid:ogami-logo@ogami' : null)
<tr>
<td style="height: 5px; background-color: #b4542a; font-size: 0; line-height: 0;">&nbsp;</td>
</tr>
<tr>
<td class="header" style="padding: 26px 32px 24px; background-color: #ffffff; text-align: center;">
<a href="{{ $url }}" style="display: inline-block; color: #17202a; text-decoration: none;">
<table cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
<td valign="middle" style="padding-right: 12px;">
@if ($logoCid)
<img src="{{ $logoCid }}" alt="" width="48" height="48" style="display: block; width: 48px; height: 48px; border: 0; border-radius: 12px;">
@else
<table width="48" height="48" cellpadding="0" cellspacing="0" role="presentation" style="width: 48px; height: 48px; background-color: #b4542a; border-radius: 12px;">
<tr><td align="center" valign="middle" style="color: #ffffff; font-size: 24px; font-weight: 800; line-height: 48px;">O</td></tr>
</table>
@endif
</td>
<td valign="middle" align="left">
<div style="color: #17202a; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15;">{{ $brand['name'] }}</div>
<div style="margin-top: 4px; color: #667085; font-size: 11px; font-weight: 600; letter-spacing: 0.08em; line-height: 1.3; text-transform: uppercase;">Business portal</div>
</td>
</tr>
</table>
</a>
</td>
</tr>
