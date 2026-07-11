@extends('layouts.vendor.app')

@section('title', '新订单提醒设置细节')

@push('css_or_js')
<style>
/* 哪吒 P3 新订单提醒设置指引页 —— nzgm- 前缀命名空间。商家后台配色 = DS §19 方向A「浅白专业」:
   骨架零彩色(白灰), accent=墨#1F2329 只做交互(主键实心/功能链带›), 状态循五族制(绿②/琥珀①/红⑤ muted tint), 强调=粗墨非彩色。 */
.nzgm{
  --ink:#1F2329; --ink2:#5A6069; --ink3:#9AA0A8; --line:#E7EAEF; --line2:#F0F2F4; --bg:#F5F6F8;
  --amberBar:#D9A521; --amberIc:#B0800F; --greenBg:#E5F1EA; --greenInk:#2B7A57; --redBg:#F9EAE8; --redInk:#AE4840;
  color:var(--ink2); font-size:14px; max-width:680px; margin:0 auto;
  font-family:"Noto Sans Armenian","Segoe UI","Microsoft YaHei","PingFang SC",sans-serif;
}
.nzgm *{box-sizing:border-box}
.nzgm .hl{color:var(--ink);font-weight:600}
.nzgm-card{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 1px 3px rgba(23,28,38,.04)}
.nzgm-head{padding:20px;margin-bottom:14px}
.nzgm-head h1{color:var(--ink);font-size:19px;font-weight:700;margin:0 0 7px;line-height:1.4}
.nzgm-head p{font-size:13.5px;color:var(--ink2);margin:0;line-height:1.65}
/* 提醒方式总览: 每条通道一张白卡, 标题带一个状态族 tag */
.nzgm-chs{display:flex;flex-direction:column;gap:11px}
.nzgm-ch{padding:15px 16px}
.nzgm-ch h3{color:var(--ink);font-size:15px;font-weight:600;margin:0 0 5px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.nzgm-ch p{margin:0;font-size:13px;line-height:1.6;color:var(--ink2)}
.nzgm-ch p + p{margin-top:6px}
.nzgm-tag{font-size:11px;font-weight:600;border-radius:999px;padding:2px 9px;white-space:nowrap}
.nzgm-tag.rec{background:var(--greenBg);color:var(--greenInk)}
.nzgm-tag.nt{background:var(--line2);color:var(--ink2)}
.nzgm-bot{font-weight:700;color:var(--ink)}
.nzgm-hint{margin:11px 2px 0;color:var(--ink3);font-size:12px;line-height:1.55}
.nzgm-sub{color:var(--ink);font-size:15.5px;font-weight:700;margin:22px 2px 12px}
.nzgm-steps{display:flex;flex-direction:column;gap:11px}
.nzgm-step{display:flex;gap:14px;align-items:flex-start;padding:15px 16px}
.nzgm-badge{flex:0 0 auto;width:34px;height:34px;border-radius:50%;background:var(--line2);color:var(--ink);font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums}
.nzgm-step h3{color:var(--ink);font-size:15px;font-weight:600;margin:0 0 4px}
.nzgm-step p{margin:0;font-size:13px;line-height:1.6;color:var(--ink2)}
/* 内联示意: 断连红条=族⑤ / 常亮胶囊=族②绿(与实际元素同色系) */
.nzgm-chip{display:inline-flex;align-items:center;gap:4px;background:var(--greenBg);color:var(--greenInk);font-size:11px;font-weight:600;border-radius:999px;padding:3px 8px;margin:0 1px;vertical-align:middle}
.nzgm-redbar{display:inline-flex;align-items:center;gap:4px;background:var(--redBg);color:var(--redInk);font-size:11px;font-weight:600;border-radius:6px;padding:3px 8px;margin:0 1px;vertical-align:middle}
/* 常见问题: 白卡 + 3px 琥珀侧条(族① 卡面形态·非整卡涂色) */
.nzgm-tsc{margin-top:16px;padding:16px 16px 16px 15px;border-left:3px solid var(--amberBar)}
.nzgm-tsc h2{color:var(--ink);font-size:14.5px;font-weight:600;margin:0 0 11px}
.nzgm-tr{border-top:1px solid var(--line2);padding:11px 0 3px}
.nzgm-tr:first-of-type{border-top:none;padding-top:0}
.nzgm-tr b{color:var(--ink);font-size:13px;font-weight:600;display:block;margin-bottom:3px}
.nzgm-tr span{font-size:12.5px;color:var(--ink2);line-height:1.55}
.nzgm-back{display:inline-flex;align-items:center;justify-content:center;gap:7px;margin-top:18px;background:var(--ink);color:#fff;border-radius:12px;padding:13px 22px;font-size:15px;font-weight:600;text-decoration:none}
.nzgm-back:hover{background:#3A4048;color:#fff}
.nzgm-note{margin-top:14px;background:var(--bg);border-radius:10px;padding:11px 13px;font-size:12px;color:var(--ink2);text-align:center;line-height:1.6}
@media (max-width:520px){
  .nzgm-head h1{font-size:18px;letter-spacing:-.3px}
}
</style>
@endpush

@section('content')
<div class="main-content-area">
  <div class="nzgm">

    <div class="nzgm-card nzgm-head">
      <h1>新订单提醒设置细节</h1>
      <p>有新订单时，系统会通过下面的方式提醒你。<b style="color:#1F2329">两条主通道一起用，基本不漏单</b>：</p>
    </div>

    <div class="nzgm-chs">
      <div class="nzgm-card nzgm-ch">
        <h3>① Telegram 推送<span class="nzgm-tag rec">推荐 · 锁屏也能收</span></h3>
        <p>绑定后，每来一单，机器人 <span class="nzgm-bot">@@Nz_order_bot</span> 会私聊发你一张订单卡片。人在店外、手机锁屏时，这是<span class="hl">唯一能弹出新单</span>的方式。</p>
        <p>到「<span class="hl">业务设置 → 通知设置</span>」按提示绑定即可；同一张卡片还能打开「邮箱提醒」作为补充。</p>
      </div>
      <div class="nzgm-card nzgm-ch">
        <h3>② 作业台响铃<span class="nzgm-tag nt">守着设备时最及时</span></h3>
        <p>登录商家后台、停留在「作业台」页面，系统每 6 秒自动检查，有新单就<span class="hl">响铃 + 队列高亮</span>。页面关掉就收不到——最好用一台设备常开着，方法见下。</p>
      </div>
    </div>

    <p class="nzgm-hint">提示：如果已用一台常开设备专门接单，可联系平台运营开「常开设备豁免」，即可不强制绑定 Telegram。</p>

    <div class="nzgm-sub">用一台常开设备接单</div>

    <div class="nzgm-steps">
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">1</div>
        <div><h3>用一台专门的设备</h3>
        <p>旧手机也行。<span class="hl">插电保持常开</span>，放在收银台或出餐口这类看得见、听得见的位置。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">2</div>
        <div><h3>连上稳定的网络</h3>
        <p>网络断了会收不到新单。真断开时，页面顶部会出现红色 <span class="nzgm-redbar">⚠️ 后台已断开连接</span> 提示，检查 WiFi 即可。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">3</div>
        <div><h3>停留在「作业台」页面</h3>
        <p>进入「今天 · 作业台」并停在这里，<span class="hl">不要锁屏或切到其他 App</span>。屏幕会自动保持常亮（右下角显示 <span class="nzgm-chip">屏幕常亮</span> 即生效）。</p></div>
      </div>
      <div class="nzgm-card nzgm-step">
        <div class="nzgm-badge">4</div>
        <div><h3>打开提示音、调大音量</h3>
        <p>确认手机<span class="hl">未静音</span>、系统音量已调大，并在后台打开提示音开关。</p></div>
      </div>
    </div>

    <div class="nzgm-card nzgm-tsc">
      <h2>常见问题</h2>
      <div class="nzgm-tr"><b>没听到响铃</b><span>确认手机未静音、音量够大，并在后台打开提示音开关。</span></div>
      <div class="nzgm-tr"><b>屏幕仍会熄灭</b><span>个别旧机型不支持网页自动常亮。请在手机「设置 → 显示 → 自动锁屏 / 息屏」里改成「永不」。</span></div>
      <div class="nzgm-tr"><b>顶部出现红条「后台已断开连接」</b><span>说明网络中断或过慢，检查 WiFi / 路由器；恢复后红条会自动消失。</span></div>
    </div>

    <div style="text-align:center">
      <a href="{{ route('vendor.workbench.index') }}" class="nzgm-back">← 回作业台</a>
    </div>
    <p class="nzgm-note">把这台设备当作店里的「接单专线」——常开、常亮、听得见，就不会漏单。</p>

  </div>
</div>
@endsection
