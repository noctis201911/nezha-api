@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
{{-- 哪吒平台官方 logo（方形·业主 0709 定为平台官方 logo）——固定展示, 不再用 config('app.name')（默认=stackfood…） --}}
<img src="https://api.nezha.am/storage/business/nezha-logo-sq.png" alt="哪吒外卖" style="height: 64px; width: 64px; border-radius: 14px; border: 0; outline: none; -ms-interpolation-mode: bicubic;">
</a>
</td>
</tr>
