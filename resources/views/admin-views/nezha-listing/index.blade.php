@extends('layouts.admin.app')

@section('title', translate('挂牌态管理'))

@section('content')
<div class="content container-fluid">

    <div class="page-header">
        <h2 class="page-header-title">
            <span class="page-header-icon"><i class="tio-bookmarks"></i></span>
            <span>{{ translate('挂牌态管理') }}</span>
        </h2>
        <p class="text-muted mb-0">
            {{ translate('挂牌态 = 这家店只在站内展示菜单和图片、不接站内订单；顾客点吸底的「联系店家下单」经 Telegram 直接找商家自行下单。平台不经手这笔交易。') }}
        </p>
    </div>

    {{-- 🔴 合规硬提示：L1-6 射程未决（业主 2026-07-21 拍板保留为提示、不做技术性硬拦） --}}
    <div class="alert alert-danger py-2" role="alert">
        <b>{{ translate('给真实商家挂牌前，先找平台负责人拍板') }}</b><br>
        <span class="small">
            {{ translate('「平台代为公开一个未经制裁名单（OFAC）姓名筛查的商家联系方式，是否落入合规红线 L1-6 射程」目前尚未裁定（见后端 docs/compliance/CHANGELOG.md 2026-07-20 条目）。当前挂牌店限平台自建的预建店铺；要把真实第三方商家放上来，先完成裁定。') }}
        </span>
    </div>

    {{-- 总闸 --}}
    <div class="card mb-3">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap">
            <div class="mr-3">
                <h5 class="mb-1">
                    {{ translate('挂牌态总闸') }}
                    @if($master_on)
                        <span class="badge badge-soft-success ml-1">{{ translate('已开启') }}</span>
                    @else
                        <span class="badge badge-soft-secondary ml-1">{{ translate('已关闭') }}</span>
                    @endif
                    <small class="text-muted ml-2">nezha_listing_status</small>
                </h5>
                <small class="text-muted d-block">
                    {{ translate('关闭后：下面所有店的挂牌态一律失效、回到未挂牌的样子。其中「未上架」的预建店本来就不进任何列表，关闸后连直链也打不开（顾客看到页面不存在）；「已上架」的店则恢复正常接单。') }}
                </small>
                @if($listed_live_count > 0)
                    <small class="text-danger d-block font-weight-bold">
                        {{ translate('注意：当前有') }} {{ $listed_live_count }} {{ translate('家【已上架】的挂牌店。关总闸 = 这些店立刻恢复站内接单——如果它们的后台没人接单，顾客的订单会没人处理。关之前先确认有人接。') }}
                    </small>
                @endif
                <small class="text-muted d-block">
                    {{ translate('顾客侧有页面缓存，开关生效最长约 60 秒。') }}
                </small>
            </div>
            <form action="{{ route('admin.nezha-listing.toggle-master') }}" method="post"
                  onsubmit="return confirm('{{ $master_on ? (translate('确定关闭挂牌态总闸？') . ($listed_live_count > 0 ? translate('当前有 ') . $listed_live_count . translate(' 家【已上架】的挂牌店会立刻恢复站内接单（后台若无人接单，顾客的单会没人处理）；') : '') . translate('未上架的预建店直链会打不开。')) : translate('确定开启挂牌态总闸？下面已开逐店开关的店会对顾客生效。') }}')">
                @csrf
                <input type="hidden" name="enable" value="{{ $master_on ? 0 : 1 }}">
                <button type="submit" class="btn {{ $master_on ? 'btn-danger' : 'btn-primary' }}">
                    {{ $master_on ? translate('关闭总闸') : translate('开启总闸') }}
                </button>
            </form>
        </div>
    </div>

    {{-- 已挂牌的店 --}}
    <div class="card mb-3">
        <div class="card-header py-2 border-0">
            <h5 class="card-title">
                {{ translate('已挂牌的店') }}
                <span class="badge badge-soft-dark ml-2">{{ count($listed) }}</span>
            </h5>
        </div>
        <div class="card-body pt-0">
            @if(count($listed) === 0)
                <div class="text-muted py-3">{{ translate('还没有店开挂牌态。用下面的搜索框找到店铺后开启。') }}</div>
            @endif

            @foreach($listed as $r)
                @php
                    $raw = is_array($r->nezha_contacts) ? $r->nezha_contacts : [];
                    $normalized = \App\CentralLogics\NezhaContacts::normalize($raw);
                    $rows = $raw;
                    while (count($rows) < 4) { $rows[] = ['method' => '', 'value' => '', 'label' => '']; }
                @endphp

                <div class="border rounded p-3 mb-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap">
                        <div>
                            <b>{{ $r->name }}</b>
                            <span class="text-muted small ml-1">ID {{ $r->id }}</span>
                            @if((int) $r->status === 1)
                                <span class="badge badge-soft-warning ml-1">{{ translate('已上架（原本可下单）') }}</span>
                            @else
                                <span class="badge badge-soft-secondary ml-1">{{ translate('未上架（预建店·仅直链可达）') }}</span>
                            @endif
                            @if(count($normalized) === 0)
                                <span class="badge badge-danger ml-1">{{ translate('无可用联系方式') }}</span>
                            @else
                                <span class="badge badge-soft-info ml-1">{{ translate('联系方式') }} {{ count($normalized) }}</span>
                            @endif
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-primary mr-1" type="button"
                                    data-toggle="collapse" data-target="#nz-contacts-{{ $r->id }}">
                                <i class="tio-edit"></i> {{ translate('编辑联系方式') }}
                            </button>
                            <form action="{{ route('admin.nezha-listing.toggle-store', $r->id) }}" method="post" class="d-inline"
                                  onsubmit="return confirm('{{ (int) $r->status === 1 ? translate('这家店已上架。关闭挂牌态后顾客会立刻能在站内下单——请先确认这家店后台真有人接单。确定关闭？') : translate('确定关闭这家店的挂牌态？关闭后未上架的它将无法通过直链访问。') }}')">
                                @csrf
                                <input type="hidden" name="enable" value="0">
                                <button type="submit" class="btn btn-sm btn-danger">{{ translate('关闭挂牌') }}</button>
                            </form>
                        </div>
                    </div>

                    @if(count($normalized) > 0)
                        <div class="small text-muted mt-2">
                            {{ translate('顾客点击后实际会去：') }}
                            @foreach($normalized as $c)
                                <span class="mr-2">
                                    [{{ $method_label[$c['method']] ?? $c['method'] }}]
                                    {{ $c['href'] ?? ($c['copy'] ? translate('复制号码 ') . $c['copy'] : translate('无跳转')) }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <div class="small text-danger mt-2">
                            {{ translate('顾客点「联系店家下单」会没有可用渠道（页面会退化成一行说明，不给死胡同）。请补一条 Telegram 或 WhatsApp。') }}
                        </div>
                    @endif

                    <div class="collapse mt-3" id="nz-contacts-{{ $r->id }}">
                        <form action="{{ route('admin.nezha-listing.update-contacts', $r->id) }}" method="post">
                            @csrf
                            <div class="small text-muted mb-2">
                                {{ translate('Telegram 填用户名（可带 @ 或整段 t.me 链接）；WhatsApp 填带国际区号的号码；电话填可直接拨的号码；微信填微信号（顾客侧走复制，不跳转）。留空的行会被丢弃。') }}
                            </div>
                            @foreach($rows as $i => $row)
                                <div class="form-row align-items-center mb-2">
                                    <div class="col-12 col-md-3 mb-2 mb-md-0">
                                        <select name="method[]" class="form-control form-control-sm">
                                            <option value="">{{ translate('— 不使用 —') }}</option>
                                            @foreach($methods as $m)
                                                <option value="{{ $m }}" {{ ($row['method'] ?? '') === $m ? 'selected' : '' }}>
                                                    {{ $method_label[$m] ?? $m }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-5 mb-2 mb-md-0">
                                        <input type="text" name="value[]" class="form-control form-control-sm"
                                               value="{{ $row['value'] ?? '' }}" placeholder="{{ translate('账号 / 号码') }}" maxlength="191">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <input type="text" name="label[]" class="form-control form-control-sm"
                                               value="{{ $row['label'] ?? '' }}" placeholder="{{ translate('备注（选填，如：中文客服）') }}" maxlength="60">
                                    </div>
                                </div>
                            @endforeach
                            <button type="submit" class="btn btn-sm btn-primary mt-1">{{ translate('保存联系方式') }}</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- 把一家店加进挂牌 --}}
    <div class="card">
        <div class="card-header py-2 border-0">
            <h5 class="card-title">{{ translate('给一家店开挂牌') }}</h5>
        </div>
        <div class="card-body pt-0">
            <form action="{{ route('admin.nezha-listing.index') }}" method="get" class="form-row align-items-center mb-3">
                <div class="col-12 col-md-6 mb-2 mb-md-0">
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ $search }}"
                           placeholder="{{ translate('输入店名或店铺 ID') }}">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">{{ translate('搜索') }}</button>
                </div>
            </form>

            @if($search !== '')
                @if(count($candidates) === 0)
                    <div class="text-muted">{{ translate('没找到未挂牌的店（已挂牌的店在上面那张表里）。') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-align-middle">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>{{ translate('店名') }}</th>
                                    <th>{{ translate('店铺状态') }}</th>
                                    <th class="text-right">{{ translate('操作') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($candidates as $c)
                                    <tr>
                                        <td>{{ $c->id }}</td>
                                        <td>{{ $c->name }}</td>
                                        <td>
                                            @if((int) $c->status === 1)
                                                <span class="badge badge-soft-warning">{{ translate('已上架·正在接单') }}</span>
                                            @else
                                                <span class="badge badge-soft-secondary">{{ translate('未上架') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            <form action="{{ route('admin.nezha-listing.toggle-store', $c->id) }}" method="post" class="d-inline"
                                                  onsubmit="return confirm('{{ (int) $c->status === 1 ? translate('这家店当前正常营业接单。开启挂牌态后，顾客将无法在站内下单，只能经 Telegram 联系商家。确定开启？') : translate('确定给这家店开启挂牌态？') }}')">
                                                @csrf
                                                <input type="hidden" name="enable" value="1">
                                                @if((int) $c->status === 1)
                                                    <input type="hidden" name="ack_active" value="1">
                                                @endif
                                                <button type="submit" class="btn btn-sm btn-primary">{{ translate('开启挂牌') }}</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        </div>
    </div>

</div>
@endsection
