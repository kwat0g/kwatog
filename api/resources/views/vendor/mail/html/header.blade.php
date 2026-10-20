@props(['url'])
@php($brand = app(\App\Common\Services\EmailBrandingService::class)->data())
@php($brandUrl = rtrim(config('app.frontend_url', config('app.url')), '/'))
@php($logoCid = $brand['logo_path'] ? 'cid:ogami-logo@ogami' : null)
<tr>
<td style="height: 5px; background-color: #b4542a; font-size: 0; line-height: 0;">&nbsp;</td>
</tr>
<tr>
<td class="header" style="padding: 26px 32px 24px; background-color: #ffffff; text-align: center;">
<a href="{{ $brandUrl }}" style="display: inline-block; color: #17202a; text-decoration: none;">
<table cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
@if ($logoCid)
<td valign="middle" style="padding-right: 12px;">
<table cellpadding="0" cellspacing="0" role="presentation" style="background-color: #ffffff;">
<tr><td style="padding: 3px;"><img src="{{ $logoCid }}" alt="{{ $brand['name'] }} logo" width="64" height="64" style="display: block; width: 64px; height: 64px; border: 0;"></td></tr>
</table>
</td>
@endif
<td valign="middle" align="left">
<div style="color: #17202a; font-size: 20px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.15;">{{ $brand['name'] }}</div>
<div style="margin-top: 4px; color: #667085; font-size: 11px; font-weight: 600; letter-spacing: 0.08em; line-height: 1.3; text-transform: uppercase;">Business portal</div>
</td>
</tr>
</table>
</a>
</td>
</tr>
