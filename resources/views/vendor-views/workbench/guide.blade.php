@extends('layouts.vendor.app')

@section('title', '别漏单 · 常开设备接单指南')

@push('css_or_js')
<style>
/* 哪吒 P3 常开设备接单指引页 —— nzgm- 前缀命名空间。商家后台配色 = DS §19 方向A「浅白专业」:
   骨架零彩色(白灰), accent=墨#1F2329 只做交互(主键实心/功能链带›), 状态循五族制(绿②/琥珀①/红⑤ muted tint), 强调=粗墨非彩色。 */
.nzgm{
  --ink:#1F2329; --ink2:#5A6069; --ink3:#9AA0A8; --line:#E7EAEF; --line2:#F0F2F4; --bg:#F5F6F8;
  --amberBar:#D9A521; --amberIc:#B0800F; --greenBg:#E5F1EA; --greenInk:#2B7A57; --redBg:#F9EAE8; --redInk:#AE4840;
  color:var(--ink2); font-size:14px; max-width:680px; margin:0 auto;
  font-family:"Noto Sans Armenian","Segoe UI","Microsoft YaHei","PingFang SC",sans-serif;
}
.nzgm *{box-sizing:border-box}
.nzgm-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 1px 3px rgba(23,28,38,.04)}
.nzgm-head{padding:20px;margin-bottom:14px}
.nzgm-head h1{color:var(--ink);font-size:19px;font-weight:700;margin:0 0 7px;line-height:1.4}
.nzgm-head p{font-size:13.5px;color:var(--ink2);margin:0;line-height:1.65}
.nzgm-check{padding:13px 15px;margin-bottom:14px;background:#F8FCF9;border-color:#DCEBE1}
.nzgm-check-title{display:flex;align-items:center;gap:7px;color:var(--ink);font-size:14px;font-weight:700;margin-bottom:9px}
.nzgm-check-title .i{font-size:17px;line-height:1}
.nzgm-check-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}
.nzgm-check-item{display:flex;align-items:center;gap:6px;min-width:0;color:var(--ink2);font-size:12px;line-height:1.35}
.nzgm-check-item b{flex:0 0 auto;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--greenBg);color:var(--greenInk);font-size:11px}
.nzgm-check-note{margin:9px 0 0;color:var(--ink3);font-size:11.5px;line-height:1.45}
.nzgm-steps{display:flex;flex-direction:column;gap:11px}
.nzgm-step{display:flex;gap:14px;align-items:flex-start;padding:15px 16px}
.nzgm-badge{flex:0 0 auto;width:34px;height:34px;border-radius:50%;background:var(--line2);color:var(--ink);font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums}
.nzgm-ic{font-size:21px;line-height:1;margin-right:3px}
.nzgm-step h3{color:var(--ink);font-size:15px;font-weight:600;margin:0 0 4px;display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.nzgm-step p{margin:0;font-size:13px;line-height:1.6;color:var(--ink2)}
.nzgm-step .hl{color:var(--ink);font-weight:600}
/* 内联示意: 断连红条=族⑤ muted / 常亮胶囊=族②绿(与实际元素同色系) */
.nzgm-chip{display:inline-flex;align-items:center;gap:4px;background:var(--greenBg);color:var(--greenInk);font-size:11px;font-weight:600;border-radius:999px;padding:3px 8px;margin:0 1px;vertical-align:middle}
.nzgm-redbar{display:inline-flex;align-items:center;gap:4px;background:var(--redBg);color:var(--redInk);font-size:11px;font-weight:600;border-radius:6px;padding:3px 8px;margin:0 1px;vertical-align:middle}
/* 故障排查: 白卡 + 3px 琥珀侧条(族① 卡面形态·非整卡涂色) */
.nzgm-tsc{margin-top:16px;padding:16px 16px 16px 15px;border-left:3px solid var(--amberBar)}
.nzgm-tsc h2{color:var(--ink);font-size:14.5px;font-weight:600;margin:0 0 11px;display:flex;align-items:center;gap:7px}
.nzgm-tsc h2 .i{color:var(--amberIc)}
.nzgm-tr{border-top:1px solid var(--line2);padding:11px 0 3px}
.nzgm-tr:first-of-type{border-top:none;padding-top:0}
.nzgm-tr b{color:var(--ink);font-size:13px;font-weight:600;display:block;margin-bottom:3px}
.nzgm-tr span{font-size:12.5px;color:var(--ink2);line-height:1.55}
.nzgm-back{display:inline-flex;align-items:center;justify-content:center;gap:7px;margin-top:18px;background:var(--ink);color:#fff;border-radius:12px;padding:13px 22px;font-size:15px;font-weight:600;text-decoration:none}
.nzgm-back:hover{background:#3A4048;color:#fff}
.nzgm-note{margin-top:14px;background:var(--bg);border-radius:10px;padding:11px 13px;font-size:12px;color:var(--ink2);text-align:center;line-height:1.6}
@media (max-width:520px){
  .nzgm-head h1{font-size:18px;letter-spacing:-.3px}
  .nzgm-check-grid{grid-template-columns:1fr;gap:6px}
  .nzgm-check-item{font-size:12.5px}
}
</style>
@endpush

@section('content')
<div class="main-content-area">
  <div class="nzgm">

    <div class="nzgm-card nzgm-head">
      <h1>📟 别漏单 · 用一台常开设备接单更省心</h1>
      <p><b style="color:#1F2329">接单不用额外开什么“模式”</b>——只要作业台开着，新单就会自动进来并响铃提醒；人在外面时，靠 Telegram 也能收单（见「通知设置」）。这页是给<b style="color:#1F2329">想更省心</b>的店：拿一台闲置手机 / 平板插电常开、专门摆着接单，页面保持在最前面，屏幕不灭、掉线有提示，就更不容易漏单。可选，不设也照常接单。</p>
    </div>

    <div class="nzgm-card nzgm-check" role="note">
      <div class="nzgm-check-title"><span class="i">✅</span><span>开始营业前，先确认这 3 件事</span></div>
      <div class="nzgm-check-grid">
        <div class="nzgm-check-item"><b>1</b><span>作业台页面在最前</span></div>
        <div class="nzgm-check-item"><b>2</b><span>手机声音和提示音已开</span></div>
        <div class="nzgm-check-item"><b>3</b><span>顶部没有红色断连条</span></div>
      </div>
      <p class="nzgm-check-note">三项都满足，再把设备放到店里看得见、听得见的位置。</p>
    </div>

    <div class="nzgm-steps">
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">1</div>
        <div><h3><span class="nzgm-ic">📱</span>准备一台闲置手机/平板</h3>
        <p>旧手机也行。<span class="hl">插上充电器保持通电</span>，固定放在店里专门接单。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">2</div>
        <div><h3><span class="nzgm-ic">🌐</span>连一个稳定的 WiFi</h3>
        <p>网络不稳就可能收不到新单。真断连时，页面顶部会出现红条 <span class="nzgm-redbar">⚠️ 后台已断开连接</span>，请及时检查 WiFi。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">3</div>
        <div><h3><span class="nzgm-ic">🔑</span>登录商家后台，打开「今天 · 作业台」</h3>
        <p>用浏览器登录商家后台，进入 <span class="hl">作业台</span> 页面并停在这里。不要锁屏、切到别的 App 或浏览器标签页，新单才会及时出现在队列里。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">4</div>
        <div><h3><span class="nzgm-ic">🔊</span>打开提示音、把音量调大</h3>
        <p>新单到达会响铃。请确认手机<span class="hl">没有静音</span>、系统音量调大，并把作业台里的提示音开关打开。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">5</div>
        <div><h3><span class="nzgm-ic">💡</span>屏幕常亮已自动开启</h3>
        <p>停在作业台页面时，屏幕<span class="hl">不会自动熄灭</span>。右下角出现 <span class="nzgm-chip">📱 屏幕常亮</span> 就表示已生效；切到后台或锁屏后，这项保护会暂停。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">6</div>
        <div><h3><span class="nzgm-ic">👀</span>放在看得见、听得见的地方</h3>
        <p>收银台或厨房出餐口都行。新单一到，响铃 + 队列高亮，第一时间接单。</p></div>
      </div>
    </div>

    <div class="nzgm-card nzgm-tsc">
      <h2><span class="i">🛟</span>遇到问题？</h2>
      <div class="nzgm-tr"><b>顶部出现红条「后台已断开连接」</b><span>说明网络断了或很慢，检查 WiFi / 路由器；恢复后红条会自动消失。</span></div>
      <div class="nzgm-tr"><b>没听到响铃</b><span>确认手机没静音、系统音量够大；并在后台把提示音开关打开、音量调大。</span></div>
      <div class="nzgm-tr"><b>屏幕还是会熄灭</b><span>个别旧机型不支持网页自动常亮。请在手机「系统设置 → 显示 → 自动锁屏/息屏」里改成「永不」。</span></div>
      <div class="nzgm-tr"><b>右下角没出现「屏幕常亮」标记</b><span>请把浏览器升级到较新版本；不影响接单，只是需要手动把息屏时间调长。</span></div>
    </div>

    <div style="text-align:center">
      <a href="{{ route('vendor.workbench.index') }}" class="nzgm-back">← 回作业台</a>
    </div>
    <p class="nzgm-note">把这台设备当成店里的「接单电话」：页面在前台、声音听得见、顶部无红条，提醒才更可靠。</p>

  </div>
</div>
@endsection
