@extends('layouts.admin.app')

@section('title', translate('开关台账'))

@push('css_or_js')
<style>
/* 哪吒超管 M3「开关台账」——作用域限定 .nz-switches, 类名 nzsw- 前缀防撞后台 Bootstrap 主题。工作台系 token(与驾驶舱同制)。 */
.nz-switches{
  --navy:#102A4C; --navy-tint:#E8EEF6;
  --nzbg:#F5F6F8; --nzcard:#FFFFFF; --nzline:#E4E7EC;
  --ink:#1A2233; --ink2:#5B6472; --ink3:#98A1B0;
  --red:#E5484D; --red-tint:#FEECEC;
  --amber:#D97A08; --amber-tint:#FCF1E3;
  --green:#2F9E6E; --green-tint:#E8F5EF;
  --grey:#8A8F98; --grey-tint:#F1F1F3;
  --mono:Consolas,"SF Mono","Cascadia Mono",monospace;
  color:var(--ink);
}
.nz-switches .nzsw-sum{background:#fff;border:1px solid var(--nzline);border-radius:12px;padding:13px 18px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.nz-switches .nzsw-sum.dev{background:var(--red-tint);border-color:#F3C2C4}
.nz-switches .nzsw-stat{display:flex;align-items:baseline;gap:7px}
.nz-switches .nzsw-stat .n{font-family:var(--mono);font-size:22px;font-weight:700}
.nz-switches .nzsw-stat .n.g{color:var(--green)} .nz-switches .nzsw-stat .n.r{color:var(--red)}
.nz-switches .nzsw-stat .k{font-size:12px;color:var(--ink2)}
.nz-switches .nzsw-verify{margin-left:auto;font-size:11.5px;color:var(--ink3);text-align:right;line-height:1.6}
.nz-switches .nzsw-verify code{font-family:var(--mono);background:var(--grey-tint);border-radius:4px;padding:1px 6px;color:var(--ink2)}
.nz-switches .nzsw-note{margin:12px 0 0;font-size:11.5px;color:var(--ink3);line-height:1.8}
.nz-switches .nzsw-note b{color:var(--red)}

.nz-switches .nzsw-tablewrap{margin-top:14px;background:#fff;border:1px solid var(--nzline);border-radius:12px;overflow-x:auto}
.nz-switches table.nzsw-tbl{width:100%;border-collapse:collapse;font-size:12.5px;min-width:1020px}
.nz-switches .nzsw-tbl th{background:var(--nzbg);color:var(--ink2);font-weight:700;font-size:11.5px;text-align:left;padding:10px 12px;border-bottom:1px solid var(--nzline);white-space:nowrap}
.nz-switches .nzsw-tbl td{padding:11px 12px;border-top:1px solid #F0F2F5;vertical-align:middle}
.nz-switches .nzsw-tbl tr:first-child td{border-top:none}
.nz-switches .nzsw-tbl tr.dev td{background:#FFF7F7}
.nz-switches .nzsw-tbl tr.dev td:first-child{box-shadow:inset 3px 0 0 var(--red)}
.nz-switches .nzsw-name{font-weight:600;color:var(--ink)}
.nz-switches .nzsw-key{font-family:var(--mono);font-size:10.5px;color:var(--ink3);margin-top:2px}
.nz-switches .nzsw-badge{display:inline-block;font-size:11px;font-weight:700;border-radius:6px;padding:2px 8px;white-space:nowrap}
.nz-switches .sec-A{background:var(--navy-tint);color:var(--navy)}
.nz-switches .sec-B{background:var(--green-tint);color:var(--green)}
.nz-switches .sec-C{background:var(--grey-tint);color:var(--ink2)}
.nz-switches .sec-D{background:var(--amber-tint);color:var(--amber)}
.nz-switches .sec-E{background:var(--navy-tint);color:var(--navy)}
.nz-switches .sec-F{background:var(--green-tint);color:var(--green)}
.nz-switches .lv-l1{background:var(--red-tint);color:var(--red);cursor:help}
.nz-switches .lv-l2{background:var(--amber-tint);color:var(--amber)}
.nz-switches .lv-l3{background:var(--grey-tint);color:var(--ink2)}
.nz-switches .lv-core{background:var(--navy);color:#fff}
.nz-switches .nzsw-pill{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;border-radius:99px;padding:3px 11px;white-space:nowrap}
.nz-switches .nzsw-pill.live{background:var(--green-tint);color:var(--green)}
.nz-switches .nzsw-pill.dormant{background:var(--grey-tint);color:var(--ink2)}
.nz-switches .nzsw-pill.unset{background:var(--grey-tint);color:var(--ink3)}
.nz-switches .nzsw-pill .dot{width:7px;height:7px;border-radius:50%}
.nz-switches .nzsw-pill.live .dot{background:var(--green)} .nz-switches .nzsw-pill.dormant .dot,.nz-switches .nzsw-pill.unset .dot{background:var(--grey)}
.nz-switches .nzsw-param{font-family:var(--mono);font-weight:700;color:var(--ink)}
.nz-switches .nzsw-special{font-size:11.5px;color:var(--ink3)}
.nz-switches .nzsw-exp{font-weight:700;font-size:11.5px}
.nz-switches .nzsw-exp.open{color:var(--green)} .nz-switches .nzsw-exp.close{color:var(--ink2)} .nz-switches .nzsw-exp.dev{color:var(--red)}
.nz-switches .nzsw-exp .devtag{display:block;font-size:10px;color:var(--red);font-weight:600;margin-top:1px}
.nz-switches .nzsw-prereq{color:var(--ink2);max-width:230px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}
.nz-switches .nzsw-ops{color:var(--amber);cursor:help;font-weight:700}
.nz-switches .nzsw-ops-none{color:var(--ink3)}
.nz-switches .nzsw-golink{color:var(--navy);font-weight:600;text-decoration:none;white-space:nowrap;font-size:12px}
.nz-switches .nzsw-noui{color:var(--ink3);font-size:11px}
.nz-switches .nzsw-upd{color:var(--ink3);font-size:11px;white-space:nowrap;cursor:default}
</style>
@endpush

@section('content')
@php($LEDGER = \App\CentralLogics\NezhaSwitchLedger::class)
<div class="content container-fluid nz-switches">
  <div class="page-header" style="margin-bottom:12px">
    <h2 class="page-header-title"><span class="page-header-icon"><i class="tio-toggle-on"></i></span><span>{{ translate('开关台账') }}</span></h2>
    <p class="text-muted mb-0" style="font-size:12.5px">{{ translate('全部灰度/合规/业务开关一屏看清 · 偏离预期立刻扎眼 · 翻闸的坑写在开关旁边。纯只读，翻闸仍去各自设置页。') }}</p>
  </div>

  {{-- 顶部汇总条(K>0 整条红 tint) --}}
  <div class="nzsw-sum {{ $summary['deviation'] > 0 ? 'dev' : '' }}">
    <div class="nzsw-stat"><span class="n">{{ $summary['dormant'] }}</span><span class="k">{{ translate('dormant 关') }}</span></div>
    <div class="nzsw-stat"><span class="n g">{{ $summary['live'] }}</span><span class="k">{{ translate('LIVE 开') }}</span></div>
    <div class="nzsw-stat"><span class="n {{ $summary['deviation'] > 0 ? 'r' : '' }}">{{ $summary['deviation'] }}</span><span class="k">{{ $summary['deviation'] > 0 ? '🔴 ' : '' }}{{ translate('偏离预期') }}</span></div>
    <div class="nzsw-verify">
      @if($lastVerify)
        {{ translate('与文档核对') }}：{{ \Carbon\Carbon::parse($lastVerify['at'])->diffForHumans() }}
        （{{ translate('偏离') }} {{ $lastVerify['deviation'] }} · {{ translate('文档漂移') }} {{ $lastVerify['md_drift'] }}）<br>
      @else
        {{ translate('与文档核对') }}：{{ translate('未跑过') }}<br>
      @endif
      <code>php artisan nezha:switches-verify</code>
    </div>
  </div>

  <p class="nzsw-note">
    {{ translate('说明') }}：{{ translate('「当前值」live 读 business_settings。') }}
    <b>{{ translate('偏离预期') }}</b>{{ translate('=安全轨(B)应开而未开、必须关(C)/未就绪(D)应关而已开——整行红描边并置顶。') }}
    {{ translate('A/E 决策类无硬预期不告警。等级徽章 L1(红) 悬停显条款原文。翻闸坑注记见') }} <span class="nzsw-ops">ⓘ</span>。
    {{ translate('本页永只读：不设开关控件（集中遥控=新误触面）。') }}
  </p>

  <div class="nzsw-tablewrap">
    <table class="nzsw-tbl">
      <thead>
        <tr>
          <th>{{ translate('开关') }}</th>
          <th>{{ translate('分区') }}</th>
          <th>{{ translate('等级') }}</th>
          <th>{{ translate('当前值') }}</th>
          <th>{{ translate('预期') }}</th>
          <th>{{ translate('开启前提') }}</th>
          <th>{{ translate('坑') }}</th>
          <th>{{ translate('设置') }}</th>
          <th>{{ translate('最后变更') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $r)
        <tr class="{{ $r['deviation'] ? 'dev' : '' }}">
          {{-- 开关名 + key --}}
          <td>
            <div class="nzsw-name">{{ $r['label'] }}</div>
            <div class="nzsw-key">{{ $r['key'] }}</div>
          </td>
          {{-- 分区 --}}
          <td><span class="nzsw-badge sec-{{ $r['section'] }}">{{ $r['section'] }}·{{ $LEDGER::sectionLabel($r['section']) }}</span></td>
          {{-- 等级(L1 hover 显条款) --}}
          <td>
            @if($r['level'])
              @php($lv = $r['is_l1'] ? 'l1' : (in_array($r['level'],['L2']) ? 'l2' : ($r['level']==='core' ? 'core' : 'l3')))
              <span class="nzsw-badge lv-{{ $lv }}" @if($r['l1_clause']) title="{{ $r['level'] }} · {{ $r['l1_clause'] }}" @endif>{{ $r['level'] }}</span>
            @else
              <span class="nzsw-upd">—</span>
            @endif
          </td>
          {{-- 当前值 --}}
          <td>
            @if($r['state'] === 'live')
              <span class="nzsw-pill live"><span class="dot"></span>{{ translate('LIVE 开') }}</span>
            @elseif($r['state'] === 'dormant')
              <span class="nzsw-pill dormant"><span class="dot"></span>{{ translate('dormant 关') }}</span>
            @elseif($r['state'] === 'unset')
              <span class="nzsw-pill unset"><span class="dot"></span>{{ translate('未设置·关') }}</span>
            @elseif($r['state'] === 'param')
              <span class="nzsw-param">{{ $r['value_disp'] }}</span>
            @else
              <span class="nzsw-special">{{ $r['value_disp'] }}</span>
            @endif
          </td>
          {{-- 预期 --}}
          <td>
            @if($r['deviation'])
              <span class="nzsw-exp dev">{{ $r['expected_disp'] }}<span class="devtag">🔴 {{ translate('偏离') }}</span></span>
            @elseif($r['expected'] === 1)
              <span class="nzsw-exp open">{{ $r['expected_disp'] }}</span>
            @elseif($r['expected'] === 0)
              <span class="nzsw-exp close">{{ $r['expected_disp'] }}</span>
            @else
              <span class="nzsw-upd">—</span>
            @endif
          </td>
          {{-- 开启前提(溢出 hover) --}}
          <td><div class="nzsw-prereq" title="{{ $r['prereq'] }}">{{ $r['prereq'] ?: '—' }}</div></td>
          {{-- 翻闸坑注记 --}}
          <td>
            @if($r['ops_note'])
              <span class="nzsw-ops" title="{{ $r['ops_note'] }}">ⓘ</span>
            @else
              <span class="nzsw-ops-none">—</span>
            @endif
          </td>
          {{-- 去设置页 --}}
          <td>
            @if($r['settings_url'])
              <a class="nzsw-golink" href="{{ $r['settings_url'] }}">{{ translate('去设置页') }} &rarr;</a>
            @else
              <span class="nzsw-noui">{{ translate('无后台UI·见注记') }}</span>
            @endif
          </td>
          {{-- 最后变更 --}}
          <td><span class="nzsw-upd" @if($r['updated_abs']) title="{{ $r['updated_abs'] }} (埃里温)" @endif>{{ $r['updated_rel'] }}</span></td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endsection
