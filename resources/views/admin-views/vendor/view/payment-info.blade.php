@extends('layouts.admin.app')
@section('title', $restaurant->name . ' - ' . translate('messages.收款信息'))
@section('content')
    <div class="content container-fluid">
        <div class="page-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <h1 class="page-header-title text-break">
                    <i class="tio-museum"></i> <span>{{ $restaurant->name }}</span>
                </h1>
            </div>
            <div class="js-nav-scroller hs-nav-scroller-horizontal">
                <span class="hs-nav-scroller-arrow-prev initial-hidden">
                    <a class="hs-nav-scroller-arrow-link" href="javascript:"><i class="tio-chevron-left"></i></a>
                </span>
                <span class="hs-nav-scroller-arrow-next initial-hidden">
                    <a class="hs-nav-scroller-arrow-link" href="javascript:"><i class="tio-chevron-right"></i></a>
                </span>
                @include('admin-views.vendor.view.partials._header', ['restaurant' => $restaurant])
            </div>
        </div>

        <div class="alert alert-info" role="alert">
            <i class="tio-info"></i>
            {{ translate('哪吒外卖收款方式: 顾客付款直接付进商家本人的下列账户, 平台不经手资金。请如实填写本店真实收款信息。') }}
        </div>

        <form action="{{ route('admin.restaurant.update-payment-info', [$restaurant->id]) }}" method="post"
              enctype="multipart/form-data">
            @csrf
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">
                        <span class="card-header-icon"><i class="tio-wallet"></i></span> &nbsp;
                        <span>{{ translate('支付宝收款 (人民币)') }}</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('收款人姓名 (顾客转账时核对用)') }}</label>
                            <input type="text" name="payee_name" class="form-control"
                                   value="{{ $restaurant->payee_name }}" placeholder="{{ translate('如: 张三') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('支付宝收款码图片') }}</label>
                            <div class="custom-file">
                                <input type="file" name="rmb_qr_image" id="rmb_qr_image" class="custom-file-input"
                                       accept=".jpg,.jpeg,.png,.webp">
                                <label class="custom-file-label" for="rmb_qr_image">{{ translate('选择图片') }}</label>
                            </div>
                            <small class="text-muted">{{ translate('支持 jpg/png/webp, 最大 2MB') }}</small>
                        </div>
                        <div class="col-12">
                            @if ($restaurant->rmb_qr_image_full_url)
                                <div class="mt-2">
                                    <label class="input-label">{{ translate('当前支付宝收款码') }}</label><br>
                                    <img src="{{ $restaurant->rmb_qr_image_full_url }}" alt="QR"
                                         style="max-width:200px;max-height:200px;border:1px solid #eee;border-radius:8px;"
                                         onerror="this.style.display='none';">
                                </div>
                            @else
                                <small class="text-danger">{{ translate('尚未上传支付宝收款码') }}</small>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">
                        <span class="card-header-icon"><i class="tio-comment"></i></span> &nbsp;
                        <span>{{ translate('微信收款 (人民币)') }}</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-light border py-2 mb-3 small">
                        <i class="tio-info-outined"></i>
                        {{ translate('微信收款码与支付宝码分开上传。顾客在结算页选「微信」时显示此码; 留空则顾客无法用微信付款, 请提醒商家提供本人微信收款二维码。') }}
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="input-label">{{ translate('微信收款码图片') }}</label>
                            <div class="custom-file">
                                <input type="file" name="wechat_qr_image" id="wechat_qr_image" class="custom-file-input"
                                       accept=".jpg,.jpeg,.png,.webp">
                                <label class="custom-file-label" for="wechat_qr_image">{{ translate('选择图片') }}</label>
                            </div>
                            <small class="text-muted">{{ translate('支持 jpg/png/webp, 最大 2MB') }}</small>
                        </div>
                        <div class="col-12">
                            @if ($restaurant->wechat_qr_image_full_url)
                                <div class="mt-2">
                                    <label class="input-label">{{ translate('当前微信收款码') }}</label><br>
                                    <img src="{{ $restaurant->wechat_qr_image_full_url }}" alt="WeChat QR"
                                         style="max-width:200px;max-height:200px;border:1px solid #eee;border-radius:8px;"
                                         onerror="this.style.display='none';">
                                </div>
                            @else
                                <small class="text-danger">{{ translate('尚未上传微信收款码') }}</small>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">
                        <span class="card-header-icon"><i class="tio-bitcoin"></i></span> &nbsp;
                        <span>{{ translate('USDT 收款') }}</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('USDT 网络') }}</label>
                            <select name="usdt_network" class="form-control">
                                <option value="">{{ translate('-- 选择网络 --') }}</option>
                                <option value="TRC20" {{ $restaurant->usdt_network == 'TRC20' ? 'selected' : '' }}>TRC20 (Tron)</option>
                                <option value="BSC" {{ $restaurant->usdt_network == 'BSC' ? 'selected' : '' }}>BSC (BEP20)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="input-label">{{ translate('USDT 收款地址') }}</label>
                            <input type="text" name="usdt_address" class="form-control"
                                   value="{{ $restaurant->usdt_address }}"
                                   placeholder="{{ translate('如: TXxxxxx... 或 0xXxxx...') }}">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body text-right">
                    <button type="submit" class="btn btn-primary">{{ translate('保存收款信息') }}</button>
                </div>
            </div>
        </form>

        {{-- 哪吒外卖: 平台美元兑人民币汇率 (顾客付款面板显示"应付 $X ≈ ¥Y") --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title">
                    <span class="card-header-icon"><i class="tio-exchange-horizontal"></i></span> &nbsp;
                    <span>{{ translate('顾客端换算汇率 (平台全局)') }}</span>
                </h5>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    {{ translate('菜价以德拉姆(֏)计。下面两个汇率用于把 ֏ 换算成人民币/美元: 浏览页显示"≈¥/≈$"参考价, 结算页显示精确"应付¥/USDT"(顾客实付=商家实收)。请填当天市场汇率, 偏差过大商家会系统性多收/少收。') }}
                </p>
                <form action="{{ route('admin.restaurant.update-rmb-rate') }}" method="post">
                    @csrf
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="input-label">{{ translate('1 元人民币 = ? ֏') }}</label>
                            <input type="number" step="0.01" min="1" max="100000" name="nezha_rate_cny_to_amd"
                                   class="form-control" value="{{ $cnyToAmd ?? 55 }}" placeholder="55" required>
                        </div>
                        <div class="col-md-3">
                            <label class="input-label">{{ translate('1 美元 = ? ֏') }}</label>
                            <input type="number" step="0.01" min="1" max="100000" name="nezha_rate_usd_to_amd"
                                   class="form-control" value="{{ $usdToAmd ?? 400 }}" placeholder="400" required>
                        </div>
                        <div class="col-md-3">
                            <label class="input-label">{{ translate('1 美元 = ? 元 (仅预存佣金折算)') }}</label>
                            <input type="number" step="0.01" min="1" max="99" name="nezha_usd_to_rmb_rate"
                                   class="form-control" value="{{ $rmbRate ?? 7.1 }}" placeholder="7.1">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary btn-block">{{ translate('更新汇率') }}</button>
                        </div>
                    </div>
                    <div class="text-muted small pt-2">
                        {{ translate('示例: 1元=55֏、1美元=400֏ → 5500֏ 订单 ≈ ¥100 / $13.75') }}
                    </div>
                </form>
            </div>
        </div>

        {{-- 哪吒外卖 B方案 组4: 预存佣金管理 (余额 / 充值 / 流水) --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title">
                    <span class="card-header-icon"><i class="tio-savings"></i></span> &nbsp;
                    <span>{{ translate('预存佣金管理') }}</span>
                </h5>
            </div>
            <div class="card-body">
                <div class="row align-items-center mb-3">
                    <div class="col-md-4">
                        <span class="text-muted d-block">{{ translate('当前预存佣金余额') }}</span>
                        <strong class="fs-18 {{ (($depositBalance ?? 0) < ($depositThreshold ?? 0)) ? 'text-danger' : 'text-success' }}">
                            {{ \App\CentralLogics\Helpers::format_currency($depositBalance ?? 0) }}
                        </strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted d-block">{{ translate('停接单阈值') }}</span>
                        <strong class="fs-18">{{ \App\CentralLogics\Helpers::format_currency($depositThreshold ?? 0) }}</strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted d-block">{{ translate('扣佣模式') }}</span>
                        @if (($depositMode ?? 0) == 1)
                            <span class="badge badge-soft-success">{{ translate('已开启 (二阶段: 扣佣+不足停接单)') }}</span>
                        @else
                            <span class="badge badge-soft-secondary">{{ translate('未开启 (一阶段: 免佣免押)') }}</span>
                        @endif
                    </div>
                </div>

                @if (($depositBalance ?? 0) < ($depositThreshold ?? 0) && ($depositMode ?? 0) == 1)
                    <div class="alert alert-warning py-2">
                        <i class="tio-warning"></i>
                        {{ translate('该餐馆预存佣金低于阈值, 当前已停止接收新单。请充值后恢复。') }}
                    </div>
                @endif

                <form action="{{ route('admin.restaurant.recharge-deposit', [$restaurant->id]) }}" method="post">
                    @csrf
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('充值/调整金额') }}</label>
                            <input type="number" step="0.01" name="amount" class="form-control"
                                   placeholder="{{ translate('正数充值, 负数扣减') }}" required>
                        </div>
                        <div class="col-md-5">
                            <label class="input-label">{{ translate('备注 (选填)') }}</label>
                            <input type="text" name="note" class="form-control" maxlength="191"
                                   placeholder="{{ translate('如: 商家微信转账充值 500') }}">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-success btn-block">{{ translate('提交') }}</button>
                        </div>
                    </div>
                    <small class="text-muted">{{ translate('提示: 充值仅记录到本系统预存佣金账本, 实际收款请商家另行线下转账给平台。') }}</small>
                </form>
                {{-- 换算小工具: 商家转了多少人民币 → 应充多少 USD --}}
                <div class="mt-3 p-3 bg-light rounded">
                    <small class="text-muted d-block mb-2"><i class="tio-calculator"></i> {{ translate('换算小工具 (不提交): 商家转了多少人民币 → 应充多少 USD?') }}</small>
                    <div class="row g-2 align-items-center">
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                                <input type="number" step="0.01" min="0" id="rmb-helper-input" class="form-control" style="max-width:120px" placeholder="{{ translate('人民币金额') }}">
                            </div>
                        </div>
                        <div class="col-auto text-muted">÷ {{ $rmbRate ?? 7.1 }} =</div>
                        <div class="col-auto"><strong class="text-success" id="rmb-usd-result">—</strong> <small class="text-muted">USD</small></div>
                    </div>
                </div>
                <script>
                (function(){
                    var inp = document.getElementById('rmb-helper-input');
                    var res = document.getElementById('rmb-usd-result');
                    var rate = {{ $rmbRate ?? 7.1 }};
                    if(inp && res) {
                        inp.addEventListener('input', function(){
                            var v = parseFloat(this.value);
                            res.textContent = (!isNaN(v) && v > 0) ? (v / rate).toFixed(2) : '—';
                        });
                    }
                })();
                </script>
            </div>
        </div>

        {{-- 预存佣金流水 --}}
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="card-title"><span>{{ translate('预存佣金流水 (最近20条)') }}</span></h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-borderless mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>{{ translate('时间') }}</th>
                                <th>{{ translate('类型') }}</th>
                                <th>{{ translate('订单') }}</th>
                                <th class="text-right">{{ translate('金额') }}</th>
                                <th class="text-right">{{ translate('余额') }}</th>
                                <th>{{ translate('备注') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $typeLabels = [
                                    'commission_deduction' => translate('订单扣佣'),
                                    'recharge' => translate('充值'),
                                    'refund_reversal' => translate('退款返还'),
                                    'adjustment' => translate('调整'),
                                ];
                            @endphp
                            @forelse (($depositLogs ?? []) as $log)
                                <tr>
                                    <td>{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $typeLabels[$log->type] ?? $log->type }}</td>
                                    <td>{{ $log->order_id ? '#' . $log->order_id : '-' }}</td>
                                    <td class="text-right {{ $log->amount < 0 ? 'text-danger' : 'text-success' }}">
                                        {{ \App\CentralLogics\Helpers::format_currency($log->amount) }}
                                    </td>
                                    <td class="text-right">{{ \App\CentralLogics\Helpers::format_currency($log->balance_after) }}</td>
                                    <td>{{ $log->note }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">{{ translate('暂无预存佣金流水') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
