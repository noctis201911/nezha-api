@extends('layouts.admin.app')

@section('title', translate('今天'))

@push('css_or_js')
<style>
/* 哪吒超管 M2-D4 驾驶舱「今天」——全部作用域限定 .nz-today, 类名 nzt- 前缀防与后台 Bootstrap 主题(.card/.row/.btn/.chip)冲突。 */
.nz-today{
  --navy:#102A4C; --navy-hover:#0B1F3A; --navy-tint:#E8EEF6;
  --nzbg:#F5F6F8; --nzcard:#FFFFFF; --nzline:#E4E7EC;
  --ink:#1A2233; --ink2:#5B6472; --ink3:#98A1B0;
  --red:#E5484D; --red-tint:#FEECEC;
  --amber:#D97A08; --amber-tint:#FCF1E3;
  --green:#2F9E6E; --green-tint:#E8F5EF;
  --grey:#8A8F98; --grey-tint:#F1F1F3;
  --mono:Consolas,"SF Mono","Cascadia Mono",monospace;
  color:var(--ink);
}
.nz-today .nzt-pulse{background:#fff;border:1px solid var(--nzline);border-radius:12px;padding:11px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.nz-today .nzt-pulse .nzt-lb{font-size:12.5px;color:var(--ink2);font-weight:600;margin-right:4px}
.nz-today .nzt-pchip{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;border-radius:99px;padding:5px 13px;border:1px solid transparent;cursor:pointer;text-decoration:none}
.nz-today .nzt-pchip .nzt-n{font-family:var(--mono);font-weight:700}
.nz-today .nzt-pchip.red{background:var(--red-tint);color:var(--red)}
.nz-today .nzt-pchip.amb{background:var(--amber-tint);color:var(--amber)}
.nz-today .nzt-pchip.nvy{background:var(--navy-tint);color:var(--navy)}
.nz-today .nzt-pulse .nzt-hint{margin-left:auto;font-size:11.5px;color:var(--ink3)}

.nz-today .nzt-cols{display:grid;grid-template-columns:1fr 352px;gap:16px;margin-top:16px;align-items:start}
@media (max-width:1100px){.nz-today .nzt-cols{grid-template-columns:1fr}}
.nz-today .nzt-card{background:var(--nzcard);border:1px solid var(--nzline);border-radius:12px;overflow:hidden}
.nz-today .nzt-card + .nzt-card{margin-top:14px}
.nz-today .nzt-chead{display:flex;align-items:center;gap:9px;padding:12px 16px;border-bottom:1px solid var(--nzline)}
.nz-today .nzt-chead .nzt-t{font-size:14px;font-weight:700}
.nz-today .nzt-chead .nzt-cnt{font-family:var(--mono);font-size:12px;font-weight:700;color:var(--ink2);background:var(--grey-tint);border-radius:99px;padding:1px 8px}
.nz-today .nzt-chead .nzt-more{margin-left:auto;font-size:12px;color:var(--navy);font-weight:600;text-decoration:none}
.nz-today .nzt-chead.red{background:var(--red-tint);border-bottom-color:#F6D5D6}
.nz-today .nzt-chead.red .nzt-t{color:var(--red)}
.nz-today .nzt-chead.red .nzt-cnt{background:#fff;color:var(--red)}
.nz-today .nzt-chead .nzt-tag{font-size:11px;color:var(--red)}
.nz-today .nzt-subhead{padding:9px 16px 3px;font-size:12px;font-weight:700;color:var(--ink2)}
.nz-today .nzt-subhead .nzt-c2{font-family:var(--mono);color:var(--red)}

.nz-today .nzt-row{display:flex;align-items:center;gap:12px;padding:10px 16px;border-top:1px solid #F0F2F5;font-size:12.5px}
.nz-today .nzt-row:first-of-type{border-top:none}
.nz-today .nzt-ent{font-weight:600;color:var(--ink);width:210px;flex:none}
.nz-today .nzt-ent small{display:block;font-weight:400;color:var(--ink3);font-size:11px;margin-top:1px}
.nz-today .nzt-amt{font-family:var(--mono);font-weight:700;color:var(--ink);text-align:right;white-space:nowrap}
.nz-today .nzt-amt small{display:block;font-weight:400;color:var(--ink3);font-size:10.5px}
.nz-today .nzt-amt.red{color:var(--red)}
.nz-today .nzt-wait{font-size:11.5px;font-weight:600;border-radius:6px;padding:3px 8px;flex:none;white-space:nowrap}
.nz-today .nzt-wait.red{color:var(--red);background:var(--red-tint);box-shadow:inset 0 0 0 1px #F3C2C4}
.nz-today .nzt-wait.amb{color:var(--amber);background:var(--amber-tint)}
.nz-today .nzt-wait.grey{color:var(--ink2);background:var(--grey-tint)}
.nz-today .nzt-chip{font-size:11px;font-weight:600;border-radius:99px;padding:2.5px 9px;white-space:nowrap}
.nz-today .nzt-chip.amb{color:var(--amber);background:var(--amber-tint)}
.nz-today .nzt-chip.grey{color:var(--ink2);background:var(--grey-tint)}
.nz-today .nzt-chip.nvy{color:var(--navy);background:var(--navy-tint)}
.nz-today .nzt-chip.red{color:var(--red);background:var(--red-tint)}
.nz-today .nzt-meta{color:var(--ink2);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}
.nz-today .nzt-acts{margin-left:auto;display:flex;gap:8px;flex:none}
.nz-today .nzt-btn{font-size:12px;font-weight:600;border-radius:7px;padding:5.5px 12px;border:1px solid transparent;white-space:nowrap;text-decoration:none;display:inline-block}
.nz-today .nzt-btn.solid{background:var(--navy);color:#fff}
.nz-today .nzt-btn.ghost{color:var(--navy);border-color:#C9D4E2;background:#fff}
.nz-today .nzt-btn.rghost{color:var(--red);border-color:#F3C2C4;background:#fff}
.nz-today .nzt-seg{display:flex;gap:8px;padding:11px 16px 3px;flex-wrap:wrap}
.nz-today .nzt-segc{font-size:12px;font-weight:600;border-radius:99px;padding:4.5px 13px;color:var(--ink2);background:var(--grey-tint);text-decoration:none}
.nz-today .nzt-segc .nzt-n{font-family:var(--mono)}
.nz-today .nzt-cfoot{padding:9px 16px;border-top:1px solid #F0F2F5;font-size:11.5px;color:var(--ink3)}
.nz-today .nzt-empty{padding:26px 16px;text-align:center;color:var(--ink3);font-size:13px}

/* 右栏 */
.nz-today .nzt-rcard{background:var(--nzcard);border:1px solid var(--nzline);border-radius:12px;padding:14px 16px}
.nz-today .nzt-rcard + .nzt-rcard{margin-top:14px}
.nz-today .nzt-rt{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px}
.nz-today .nzt-rt .nzt-more{margin-left:auto;font-size:11.5px;color:var(--navy);font-weight:600;text-decoration:none}
.nz-today .nzt-kpis{margin-top:11px;display:flex;flex-direction:column;gap:11px}
.nz-today .nzt-kpi .nzt-k{font-size:11.5px;color:var(--ink2)}
.nz-today .nzt-kpi .nzt-v{font-family:var(--mono);font-size:19px;font-weight:700;margin-top:2px}
.nz-today .nzt-kpi .nzt-v small{font-size:11px;color:var(--ink3);font-weight:400;font-family:var(--mono)}
.nz-today .nzt-foot-note{margin-top:10px;padding-top:9px;border-top:1px dashed var(--nzline);font-size:10.5px;color:var(--ink3);line-height:1.7}
.nz-today .nzt-hline{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink2);padding:5px 0}
.nz-today .nzt-dot{width:8px;height:8px;border-radius:50%;flex:none}
.nz-today .nzt-dot.g{background:var(--green)} .nz-today .nzt-dot.a{background:var(--amber)}
.nz-today .nzt-hline .nzt-hv{margin-left:auto;font-family:var(--mono);font-size:11.5px;color:var(--ink)}
.nz-today .nzt-digest{margin-top:9px;font-size:12px;color:var(--ink2);line-height:1.85;background:var(--nzbg);border-radius:8px;padding:10px 12px}
</style>
@endpush

@section('content')
<div class="content container-fluid nz-today">
  <div class="page-header" style="margin-bottom:12px">
    <h2 class="page-header-title"><span class="page-header-icon"><i class="tio-today"></i></span><span>{{ translate('今天') }}</span></h2>
    <p class="text-muted mb-0" style="font-size:12.5px">{{ translate('此刻需要平台处理的事，一屏看清。计数与列表页/铃铛同源。') }}</p>
  </div>

  {{-- 待办总览条(0 桶隐藏) --}}
  <div class="nzt-pulse">
    <span class="nzt-lb">{{ translate('待办') }}</span>
    @forelse($wb['pulse'] as $p)
      <a href="{{ $p['href'] }}" class="nzt-pchip {{ $p['tone'] }}">{{ $p['label'] }} <span class="nzt-n">{{ $p['count'] }}</span></a>
    @empty
      <span class="nzt-chip grey">{{ translate('暂无待办') }}</span>
    @endforelse
    <span class="nzt-hint">{{ translate('0 的桶自动隐藏 · 点击锚到对应卡') }}</span>
  </div>

  <div class="nzt-cols">
    {{-- ================= 左栏 ================= --}}
    <div class="nzt-lcol">

      {{-- ① 钱的队列 --}}
      @if($wb['money']['total'] > 0)
      <div class="nzt-card" id="nzt-card-money">
        <div class="nzt-chead red">
          <span class="nzt-t">{{ translate('钱的队列') }}</span>
          <span class="nzt-cnt">{{ $wb['money']['total'] }}</span>
          <span class="nzt-tag">{{ translate('最先处理 · 关联 L1 退款红线') }}</span>
          <a class="nzt-more" href="{{ $wb['money']['more_route'] }}">{{ translate('退款中心') }} &rarr;</a>
        </div>
        @if($wb['money']['overdue_count'] > 0)
          <div class="nzt-subhead">{{ translate('逾期未退款') }} <span class="nzt-c2">{{ $wb['money']['overdue_count'] }}</span>（{{ translate('按逾期时长置顶') }}）</div>
          @foreach($wb['money']['overdue_rows'] as $r)
          <div class="nzt-row">
            <span class="nzt-ent">{{ $r['shop'] }}<small>单 #{{ $r['order_id'] }} · {{ translate('原渠道') }}：{{ $r['channel'] }}</small></span>
            <span class="nzt-amt red" style="width:118px">{{ $r['amount_amd'] }}<small>≈¥{{ number_format($r['amount_cny']) }}</small></span>
            <span class="nzt-wait {{ $r['tone'] }}">{{ $r['overdue_txt'] }}</span>
            <span class="nzt-acts"><a class="nzt-btn ghost" href="{{ $r['route'] }}">{{ translate('详情') }}</a></span>
          </div>
          @endforeach
        @endif
        @if($wb['money']['dispute_on'] && $wb['money']['dispute_count'] > 0)
          <div class="nzt-subhead">{{ translate('争议待裁决') }} <span class="nzt-c2">{{ $wb['money']['dispute_count'] }}</span></div>
          <div class="nzt-row">
            <span class="nzt-meta">{{ translate('有待裁决的退款争议，进裁决台逐条处理') }}</span>
            <span class="nzt-acts"><a class="nzt-btn solid" href="{{ $wb['money']['dispute_route'] }}">{{ translate('去裁决') }}</a></span>
          </div>
        @endif
      </div>
      @endif

      {{-- ② 资金审核队列(dormant 关时 total=0 → 整卡隐藏, 真实状态) --}}
      @if($wb['funds']['total'] > 0)
      <div class="nzt-card" id="nzt-card-funds">
        <div class="nzt-chead">
          <span class="nzt-t">{{ translate('资金审核队列') }}</span>
          <span class="nzt-cnt">{{ $wb['funds']['total'] }}</span>
          <a class="nzt-more" href="{{ $wb['funds']['topup_route'] }}">{{ translate('押金与充值') }} &rarr;</a>
        </div>
        @foreach($wb['funds']['rows'] as $r)
        <div class="nzt-row">
          <span class="nzt-ent">{{ $r['shop'] }}</span>
          <span class="nzt-chip nvy">{{ $r['label'] }}</span>
          <span class="nzt-amt" style="width:118px">{{ $r['amount_amd'] }}<small>≈¥{{ number_format($r['amount_cny']) }}</small></span>
          <span class="nzt-acts"><a class="nzt-btn solid" href="{{ $r['route'] }}">{{ translate('审核') }}</a></span>
        </div>
        @endforeach
      </div>
      @endif

      {{-- ③ 订单异常 --}}
      @if($wb['exceptions']['count'] > 0)
      <div class="nzt-card" id="nzt-card-exceptions">
        <div class="nzt-chead">
          <span class="nzt-t">{{ translate('订单异常') }}</span>
          <span class="nzt-cnt">{{ $wb['exceptions']['count'] }}</span>
          <a class="nzt-more" href="{{ $wb['exceptions']['more_route'] }}">{{ translate('订单') }} &rarr;</a>
        </div>
        @foreach($wb['exceptions']['rows'] as $r)
        <div class="nzt-row">
          <span class="nzt-ent">{{ $r['shop'] }}<small>单 #{{ $r['id'] }}</small></span>
          <span class="nzt-chip amb">{{ $r['reason'] }}</span>
          <span class="nzt-wait {{ $r['tone'] }}">{{ $r['wait_txt'] }}</span>
          <span class="nzt-acts"><a class="nzt-btn ghost" href="{{ $r['route'] }}">{{ translate('处理') }}</a></span>
        </div>
        @endforeach
      </div>
      @endif

      {{-- ④ 审核台(评价段后台无功能→已去除, 另立项; 四段: UGC/入驻/广告/KYC) --}}
      @if($wb['audit']['total'] > 0)
      <div class="nzt-card" id="nzt-card-audit">
        <div class="nzt-chead">
          <span class="nzt-t">{{ translate('审核台') }}</span>
          <span class="nzt-cnt">{{ $wb['audit']['total'] }}</span>
        </div>
        <div class="nzt-seg">
          @foreach($wb['audit']['segments'] as $s)
            <a class="nzt-segc" href="{{ $s['route'] }}">{{ $s['label'] }} <span class="nzt-n">{{ $s['count'] }}</span></a>
          @endforeach
        </div>
        @foreach($wb['audit']['segments'] as $s)
        <div class="nzt-row">
          <span class="nzt-meta">{{ $s['label'] }}：{{ $s['count'] }} {{ translate('条待审') }}</span>
          <span class="nzt-acts"><a class="nzt-btn ghost" href="{{ $s['route'] }}">{{ translate('去处理') }}</a></span>
        </div>
        @endforeach
        <div class="nzt-cfoot">{{ translate('各段计数与对应审核页同源；通过/驳回在各自审核页操作（带后果确认）。') }}</div>
      </div>
      @endif

      {{-- ⑤ 商家健康 --}}
      @if($wb['merchant']['total'] > 0)
      <div class="nzt-card" id="nzt-card-merchant">
        <div class="nzt-chead">
          <span class="nzt-t">{{ translate('商家健康') }}</span>
          <span class="nzt-cnt">{{ $wb['merchant']['total'] }}</span>
          <a class="nzt-more" href="{{ $wb['merchant']['more_route'] }}">{{ translate('商家列表') }} &rarr;</a>
        </div>
        @foreach($wb['merchant']['rows'] as $r)
        <div class="nzt-row">
          <span class="nzt-ent">{{ $r['shop'] }}</span>
          <span class="nzt-chip {{ $r['tone'] }}">{{ $r['chip'] }}</span>
          <span class="nzt-meta">{{ $r['meta'] }}</span>
          <span class="nzt-acts"><a class="nzt-btn ghost" href="{{ $r['route'] }}">{{ translate('查看') }}</a></span>
        </div>
        @endforeach
      </div>
      @endif

      @unless($wb['has_any'])
      <div class="nzt-card"><div class="nzt-empty">{{ translate('今天没有待办。可以去看看「洞察」。') }}</div></div>
      @endunless

    </div>

    {{-- ================= 右栏 ================= --}}
    <div class="nzt-rcol">
      {{-- 右① 今日经营(L1-1 呈现纪律: 只出记录口径, 禁"平台营收/流水") --}}
      <div class="nzt-rcard">
        <div class="nzt-rt">{{ translate('今日经营') }}</div>
        <div class="nzt-kpis">
          <div class="nzt-kpi"><div class="nzt-k">{{ translate('平台单量') }}</div><div class="nzt-v">{{ number_format($wb['today']['orders']) }}</div></div>
          <div class="nzt-kpi"><div class="nzt-k">{{ translate('商家自收款合计（记录口径 · 平台不经手）') }}</div><div class="nzt-v">{{ $wb['today']['collected_amd'] }} <small>≈¥{{ number_format($wb['today']['collected_cny']) }}</small></div></div>
          <div class="nzt-kpi"><div class="nzt-k">{{ translate('应计佣金') }}</div><div class="nzt-v">{{ $wb['today']['commission_amd'] }} <small>≈¥{{ number_format($wb['today']['commission_cny']) }}</small></div></div>
        </div>
        <div class="nzt-foot-note">{{ translate('记录口径 · 平台不经手；换算走全站单一汇率源。图表在「洞察」，驾驶舱不放装饰图表。') }}</div>
      </div>

      {{-- 右② 系统健康(只放有现成源的行) --}}
      @if(count($wb['sys']['rows']) > 0)
      <div class="nzt-rcard">
        <div class="nzt-rt">{{ translate('系统健康') }}</div>
        <div style="margin-top:8px">
          @foreach($wb['sys']['rows'] as $h)
          <div class="nzt-hline"><span class="nzt-dot {{ $h['ok'] ? 'g' : 'a' }}"></span>{{ $h['label'] }}<span class="nzt-hv">{{ $h['value'] }}</span></div>
          @endforeach
        </div>
      </div>
      @endif

      {{-- 右③ 反馈日报(昨日) --}}
      @if($wb['digest'])
      <div class="nzt-rcard">
        <div class="nzt-rt">{{ translate('反馈日报') }} · {{ $wb['digest']['date'] }}<a class="nzt-more" href="{{ route('admin.nezha-cs.index') }}">{{ translate('客服中心') }} &rarr;</a></div>
        <div class="nzt-digest">{{ $wb['digest']['degraded'] ? translate('（当期仅统计数字，AI 摘要未生成）') : ($wb['digest']['summary'] ?: translate('当期无摘要')) }}</div>
      </div>
      @endif

      {{-- 右④ 差评预警 --}}
      <div class="nzt-rcard">
        <div class="nzt-rt">{{ translate('差评预警') }}<a class="nzt-more" href="{{ $wb['bad_review']['route'] }}">{{ translate('去处理') }} &rarr;</a></div>
        <div style="margin-top:8px;font-size:12.5px;color:var(--ink2)">
          @if($wb['bad_review']['count'] > 0)
            {{ translate('未回复差评') }} <span style="font-family:var(--mono);font-weight:700;color:var(--red)">{{ $wb['bad_review']['count'] }}</span> {{ translate('条') }} · {{ translate('涉及') }} {{ $wb['bad_review']['shops'] }} {{ translate('家商家') }} · {{ translate('最近') }} {{ \App\CentralLogics\NezhaBadReview::WINDOW_DAYS }} {{ translate('天') }}
          @else
            {{ translate('近 7 天没有未回复的差评') }}
          @endif
        </div>
      </div>
    </div>
  </div>
</div>
@endsection

@push('script')
<script>
// 待办总览条 chip → 平滑锚到对应卡(纯前端, 无请求)。
(function(){
  document.querySelectorAll('.nz-today .nzt-pchip[href^="#"]').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var el = document.querySelector(this.getAttribute('href'));
      if(el){ el.scrollIntoView({behavior:'smooth', block:'center'}); el.style.transition='box-shadow .2s'; el.style.boxShadow='0 0 0 3px rgba(16,42,76,.18)'; setTimeout(function(){el.style.boxShadow='';}, 900); }
    });
  });
})();
</script>
@endpush
