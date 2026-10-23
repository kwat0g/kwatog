@props([
    'url',
    'align' => 'center',
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}" style="padding: 0 0 22px;">
<!--[if mso]>
<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:48px;v-text-anchor:middle;width:280px;" arcsize="12%" stroke="f" fillcolor="#b4542a">
<w:anchorlock/>
<center style="color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;">{{ $slot }}</center>
</v:roundrect>
<![endif]-->
<!--[if !mso]><!-- -->
<a href="{{ $url }}" target="_blank" rel="noopener" style="display:inline-block;min-width:200px;min-height:48px;padding:0 22px;border-radius:7px;background-color:#b4542a;color:#ffffff;font-family:Arial,sans-serif;font-size:16px;font-weight:700;line-height:48px;text-align:center;text-decoration:none;">{{ $slot }}</a>
<!--<![endif]-->
<p style="margin:12px 0 0;text-align:center;font-size:13px;line-height:20px;"><a href="{{ $url }}" target="_blank" rel="noopener" style="color:#8c3c1e;text-decoration:underline;">Open this link if the button does not work</a></p>
</td>
</tr>
</table>
