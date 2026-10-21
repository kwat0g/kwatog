@props(['preheader' => null, 'title' => null])
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
<title>{{ $title ?: config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
body, body *:not(html):not(style):not(br):not(tr):not(code) {
font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol';
}
body { background-color: #f3f5f7; color: #475467; }
.wrapper, .body { background-color: #f3f5f7; }
.inner-body { width: 100% !important; max-width: 600px !important; background-color: #ffffff; border: 1px solid #e4e7ec; border-radius: 14px; box-shadow: 0 8px 24px rgba(16, 24, 40, 0.07); }
.content-cell { padding: 38px 42px 36px !important; }
h1 { color: #17202a; font-size: 24px !important; letter-spacing: -0.02em; line-height: 1.25 !important; margin-bottom: 20px !important; }
h2 { color: #344054; font-size: 16px !important; margin-top: 28px !important; }
p, li { color: #475467; font-size: 15px !important; line-height: 1.65 !important; }
a { color: #a44725; }
.table table, .content-cell > table:not(.action) { width: 100% !important; margin: 24px 0 !important; border: 1px solid #eaecf0; border-collapse: collapse; }
.table th, .content-cell > table:not(.action) th { background-color: #f9fafb; color: #667085; font-size: 11px !important; letter-spacing: 0.06em; padding: 11px 14px !important; text-transform: uppercase; }
.table td, .content-cell > table:not(.action) td { color: #344054; font-size: 14px !important; line-height: 1.5 !important; padding: 11px 14px !important; border-bottom: 1px solid #f2f4f7; }
.table tr:last-child td, .content-cell > table:not(.action) tr:last-child td { border-bottom: 0; }
.action { margin: 30px auto !important; }
.button-primary, .button-blue { background-color: #b4542a !important; border-color: #b4542a !important; border-radius: 7px !important; font-size: 14px !important; font-weight: 700 !important; letter-spacing: 0.01em; }
.panel { border-left-color: #b4542a !important; border-radius: 0 9px 9px 0; }
.panel-content { background-color: #fff8f4; color: #475467; padding: 16px 18px !important; }
.panel-content p { color: #475467; }
.footer { width: 100% !important; max-width: 600px !important; }
@media (prefers-color-scheme: dark) {
body, .wrapper, .body { background-color: #1f1b16 !important; color: #eee9e2 !important; }
.inner-body, .header { background-color: #29241f !important; border-color: #51483f !important; }
.brand-name { color: #f3f0ea !important; }
.brand-tagline { color: #c5bcb1 !important; }
.logo-light { display: none !important; }
.logo-dark { display: block !important; }
.logo-backing { background-color: transparent !important; }
h1, h2, p, li, .table td, .content-cell > table:not(.action) td { color: #eee9e2 !important; }
.table th, .content-cell > table:not(.action) th, .panel-content { background-color: #342d26 !important; color: #eee9e2 !important; }
.content a { color: #f4a582 !important; }
.content .action a { color: #ffffff !important; }
.footer, .footer td, .footer div, .footer a { color: #c5bcb1 !important; }
.footer .footer-brand-name { color: #f3f0ea !important; }
.footer .footer-divider { background-color: #51483f !important; }
}
@media only screen and (max-width: 640px) {
.content { table-layout: fixed !important; }
.inner-body, .footer { width: 100% !important; max-width: 100% !important; }
.content-cell { padding: 30px 24px 28px !important; }
.content-cell > table:not(.action) { table-layout: fixed !important; }
.content-cell > table:not(.action) th, .content-cell > table:not(.action) td { padding: 8px 6px !important; overflow-wrap: anywhere; }
}
@media only screen and (max-width: 500px) {
.button { width: 100% !important; text-align: center !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>
@if (! empty($preheader))
<div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; mso-hide:all;">{{ $preheader }}</div>
@endif

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
