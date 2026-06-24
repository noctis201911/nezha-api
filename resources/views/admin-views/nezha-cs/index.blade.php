@extends('layouts.admin.app')
@section('title', translate('AI在线客服'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <h1 class="page-header-title"><i class="tio-online"></i> {{ translate('AI 在线客服「小哪」') }}</h1>
        </div>

        <div class="alert alert-soft-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('顾客在「联系客服」里发消息时，小哪会自动处理：通用问题(下单/支付/营业/订单状态)直接回答；涉及钱/退款/投诉等转引导联系商家；联系不上商家会给电话+催商家+留工单；还能帮顾客和本地骑手互译。小哪绝不承诺退款、不碰资金。') }}
            @if (!$hasKey)
                <br><span class="text-danger">{{ translate('注意：尚未配置 AI 接口密钥(nezha_cs_ai_api_key)，AI 无法回复。') }}</span>
            @endif
        </div>

        {{-- 设置 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-header-title">{{ translate('开关与设置') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.nezha-cs.settings') }}">
                    @csrf
                    <div class="row">
                        <div class="col-sm-6 form-group mb-3">
                            <label class="input-label">{{ translate('AI 客服总开关') }}</label>
                            <select name="nezha_cs_ai_status" class="form-control">
                                <option value="0" {{ $status != 1 ? 'selected' : '' }}>{{ translate('关闭(顾客客服消息不自动处理)') }}</option>
                                <option value="1" {{ $status == 1 ? 'selected' : '' }}>{{ translate('开启(自动回复/转接)') }}</option>
                            </select>
                            <small class="text-danger">{{ translate('⚠️ 开启=对真实顾客生效。') }}</small>
                        </div>
                        <div class="col-sm-6 form-group mb-3">
                            <label class="input-label">{{ translate('自动转达商家开关') }}</label>
                            <select name="nezha_cs_merchant_relay_status" class="form-control">
                                <option value="0" {{ $relay != 1 ? 'selected' : '' }}>{{ translate('关闭(只引导顾客自己联系商家)') }}</option>
                                <option value="1" {{ $relay == 1 ? 'selected' : '' }}>{{ translate('开启(订单问题时自动给商家发提醒)') }}</option>
                            </select>
                            <small class="text-muted">{{ translate('开启后，顾客就订单求助时，系统会自动给对应商家捎个信(30分钟限一次)。') }}</small>
                        </div>
                        <div class="col-sm-12 form-group mb-3">
                            <label class="input-label">{{ translate('小哪能回答的资料(FAQ)') }}</label>
                            <textarea name="nezha_cs_faq" class="form-control" rows="12">{{ $faq }}</textarea>
                            <small class="text-muted">{{ translate('小哪只会照这里的内容回答通用问题。请只写真实准确的信息，写错了小哪会照着告诉顾客。留空则用系统默认。') }}</small>
                        </div>
                        <div class="col-sm-6 form-group mb-3">
                            <label class="input-label">{{ translate('AI 模型') }}</label>
                            <input type="text" name="nezha_cs_ai_model" class="form-control" value="{{ $model }}">
                            <small class="text-muted">{{ translate('默认 deepseek-chat。非必要不用改。') }}</small>
                        </div>
                        <div class="col-sm-6 form-group mb-3">
                            <label class="input-label">反馈日报开关</label>
                            <select name="nezha_feedback_digest_status" class="form-control">
                                <option value="0" {{ ($digestStatus ?? 0) != 1 ? 'selected' : '' }}>关闭（不生成每日反馈日报）</option>
                                <option value="1" {{ ($digestStatus ?? 0) == 1 ? 'selected' : '' }}>开启（每天中午12点总结昨日反馈，发超管Telegram）</option>
                            </select>
                            <small class="text-muted">每天把昨日评价/退款/客服反馈用 AI 总结成摘要+改进点，发到超管 Telegram，并在下方留历史。AI 走客服同一管线（已脱敏）；关时不生成。</small>
                        </div>
                        <div class="col-sm-12 form-group mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">{{ translate('保存设置') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- 运营数据助手：超管问小哪 --}}
        <div class="card mb-3">
            <div class="card-header"><h5 class="card-header-title"><i class="tio-chat-outlined"></i> {{ translate('问小哪（运营助手）') }}</h5></div>
            <div class="card-body">
                <small class="text-muted d-block mb-2">{{ translate('问问客服运营情况，例如：最近顾客问得最多的是什么？哪些问题没解决？差评集中在哪？— 基于近 7 天真实客服数据回答。') }}</small>
                <form method="POST" action="{{ route('admin.nezha-cs.ask') }}">
                    @csrf
                    <div class="input-group">
                        <input type="text" name="question" class="form-control" maxlength="500" placeholder="{{ translate('例如：最近顾客最常问什么？哪些没解决？') }}" value="{{ session('cs_admin_q') }}">
                        <button type="submit" class="btn btn-primary">{{ translate('问一下') }}</button>
                    </div>
                </form>
                @if (session('cs_admin_a'))
                    <div class="alert alert-soft-secondary mt-3 mb-0" style="white-space: pre-wrap;">{{ session('cs_admin_a') }}</div>
                @endif
            </div>
        </div>

        {{-- 反馈日报历史(方案A)：每天自动生成的「昨日反馈摘要 + 改进点」 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-header-title"><i class="tio-receipt-outlined"></i> 反馈日报历史</h5>
            </div>
            <div class="card-body">
                <small class="text-muted d-block mb-3">系统每天中午 12 点（埃里温时间）自动把昨日顾客反馈（评价/退款/客服）用 AI 总结成摘要+改进点（数字来自真实统计，文字由 AI 归纳，已脱敏）。开关在上方「反馈日报开关」。下面是最近 14 天。</small>
                @forelse ($digests as $d)
                    @php $cc = json_decode($d->counts ?? '{}', true) ?: []; @endphp
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong>{{ $d->digest_date }}</strong>
                            <span class="text-muted" style="font-size:12px;">
                                评价 {{ $cc['reviews_total'] ?? 0 }}（差评 {{ $cc['reviews_bad'] ?? 0 }}）
                                · 退款 {{ $cc['refunds_total'] ?? 0 }}
                                · 客服好/差 {{ $cc['cs_fb_pos'] ?? 0 }}/{{ $cc['cs_fb_neg'] ?? 0 }}
                                · 工单 {{ $cc['cs_open_tickets'] ?? 0 }}
                                @if ($d->degraded)<span class="badge badge-soft-warning ml-1">仅统计</span>@endif
                            </span>
                        </div>
                        <div style="white-space: pre-wrap; font-size: 13px;">{{ $d->summary }}</div>
                    </div>
                @empty
                    <p class="text-center text-muted py-4 mb-0">暂无日报。开启「反馈日报开关」后，每天中午 12 点自动生成；也可让技术先手动跑一次预览。</p>
                @endforelse
            </div>
        </div>

        {{-- 待处理工单 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-header-title">{{ translate('待跟进工单(顾客联系不上商家等)') }} ({{ $tickets->total() }})</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('工单') }}</th>
                            <th>{{ translate('类型') }}</th>
                            <th>{{ translate('订单') }}</th>
                            <th>{{ translate('商家') }}</th>
                            <th>{{ translate('说明') }}</th>
                            <th>{{ translate('时间') }}</th>
                            <th>{{ translate('操作') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tickets as $t)
                            <tr>
                                <td>#{{ $t->id }}</td>
                                <td>{{ $t->type == 'cant_reach' ? translate('联系不上商家') : $t->type }}</td>
                                <td>{{ $t->order_id ? '#' . $t->order_id : '—' }}</td>
                                <td>{{ $t->vendor_id ? '#' . $t->vendor_id : '—' }}</td>
                                <td class="text-muted">{{ $t->note }}</td>
                                <td>{{ $t->created_at }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.nezha-cs.ticket.close', $t->id) }}" onsubmit="return confirm('{{ translate('确认已处理完这张工单？') }}')">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ translate('标记已处理') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">{{ translate('暂无待跟进工单') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($tickets->hasPages())
            <div class="mt-3 d-flex justify-content-end">{{ $tickets->links() }}</div>
        @endif

        {{-- 顾客对客服的评价：重点看差评 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-header-title">
                    {{ translate('顾客服务评价') }}
                    <span class="badge badge-soft-success ml-2">👍 {{ $fbPos }}</span>
                    <span class="badge badge-soft-danger ml-1">👎 {{ $fbNeg }}</span>
                </h5>
            </div>
            <div class="card-body">
                <small class="text-muted">{{ translate('顾客在对话里对客服的评价（好评/差评全文）。定期看看负反馈集中在什么问题、好做改进。') }}</small>
            </div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-align-middle card-table mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>{{ translate('时间') }}</th>
                            <th>{{ translate('评价') }}</th>
                            <th>{{ translate('顾客') }}</th>
                            <th>{{ translate('反馈内容') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($feedback as $f)
                            <tr>
                                <td class="text-nowrap">{{ $f->created_at }}</td>
                                <td>
                                    @if ($f->sentiment == 'negative')
                                        <span class="badge badge-soft-danger">👎 {{ translate('差评') }}</span>
                                    @else
                                        <span class="badge badge-soft-success">👍 {{ translate('好评') }}</span>
                                    @endif
                                </td>
                                <td>{{ $f->user_id ? '#' . $f->user_id : '—' }}</td>
                                <td class="{{ $f->sentiment == 'negative' ? 'text-danger' : '' }}">{{ $f->comment }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">{{ translate('暂无评价') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
