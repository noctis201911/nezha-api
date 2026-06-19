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

        <style>
            /* 哪吒: 汉化 Bootstrap custom-file 默认英文按钮 "Browse" */
            .content .custom-file-label::after { content: "{{ translate('选择图片') }}"; }
            /* 收款码未上传时的占位框 (替代孤零零的红字, 与已上传二维码预览同尺寸) */
            .content .qr-empty-box {
                display: flex; align-items: center; justify-content: center;
                width: 130px; height: 130px;
                border: 1px dashed #d0d5dd; border-radius: 8px;
                color: #98a2b3; font-size: 12px; line-height: 1.5;
                text-align: center; background: #fafbfc; padding: 8px;
            }
        </style>

        <form action="{{ route('admin.restaurant.update-payment-info', [$restaurant->id]) }}" method="post"
              enctype="multipart/form-data">
            @csrf
            <div class="card mb-2">
                <div class="card-header">
                    <h5 class="card-title">
                        <span class="card-header-icon"><i class="tio-wallet"></i></span> &nbsp;
                        <span>{{ translate('支付宝收款 (人民币)') }}</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="form-group mb-2">
                        <label class="input-label">{{ translate('收款人姓名 (顾客转账时核对用)') }}</label>
                        <input type="text" name="payee_name" class="form-control"
                               value="{{ $restaurant->payee_name }}" placeholder="{{ translate('如: 张三') }}">
                    </div>
                    <div class="row g-3 align-items-start">
                        <div class="col-md-7">
                            <label class="input-label">{{ translate('支付宝收款码图片') }}</label>
                            <div class="custom-file">
                                <input type="file" name="rmb_qr_image" id="rmb_qr_image" class="custom-file-input"
                                       accept=".jpg,.jpeg,.png,.webp">
                                <label class="custom-file-label" for="rmb_qr_image">{{ translate('未选择文件') }}</label>
                            </div>
                            <small class="text-muted">{{ translate('支持 jpg/png/webp, 最大 2MB') }}</small>
                        </div>
                        <div class="col-md-5">
                            <div id="rmb_qr_box" style="{{ $restaurant->rmb_qr_image_full_url ? '' : 'display:none;' }}">
                                <label class="input-label d-block mb-1" id="rmb_qr_label">{{ translate('当前支付宝收款码') }}</label>
                                <div style="display:inline-block;background:#fff;padding:6px;border:1px solid #eee;border-radius:8px;line-height:0;">
                                    <img id="rmb_qr_current" src="{{ $restaurant->rmb_qr_image_full_url ?? '' }}" alt="QR"
                                         style="display:block;max-width:120px;max-height:120px;" onerror="var b=document.getElementById('rmb_qr_box'),e=document.getElementById('rmb_qr_empty');if(b)b.style.display='none';if(e)e.style.display='';">
                                </div>
                            </div>
                            <div id="rmb_qr_empty" style="{{ $restaurant->rmb_qr_image_full_url ? 'display:none;' : '' }}">
                                <div class="qr-empty-box">{{ translate('尚未上传支付宝收款码') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- 微信收款已下线: 平台放弃微信支付方式(2026-06-19), 商家无需再上传微信收款码 --}}

            <div class="card mb-2">
                <div class="card-header">
                    <h5 class="card-title">
                        <span class="card-header-icon"><i class="tio-bitcoin"></i></span> &nbsp;
                        <span>{{ translate('USDT 收款') }}</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-start">
                        <div class="col-md-7">
                            <div class="form-group mb-3">
                                <label class="input-label">{{ translate('USDT 网络') }}</label>
                                <select name="usdt_network" class="form-control">
                                    <option value="">{{ translate('-- 选择网络 --') }}</option>
                                    <option value="TRC20" {{ $restaurant->usdt_network == 'TRC20' ? 'selected' : '' }}>TRC20 (Tron)</option>
                                    <option value="BSC" {{ $restaurant->usdt_network == 'BSC' ? 'selected' : '' }}>BSC (BEP20)</option>
                                </select>
                            </div>
                            <div class="form-group mb-0">
                                <label class="input-label">{{ translate('USDT 收款地址') }}</label>
                                <input type="text" name="usdt_address" class="form-control"
                                       value="{{ $restaurant->usdt_address }}"
                                       placeholder="{{ translate('如: TXxxxxx... 或 0xXxxx...') }}">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div id="usdt_qr_box" style="{{ $restaurant->usdt_address ? '' : 'display:none;' }}">
                                <label class="input-label d-block mb-1">{{ translate('USDT 收款二维码 (顾客扫此码转账)') }}</label>
                                <div style="display:inline-block;max-width:140px;background:#fff;padding:6px;border:1px solid #eee;border-radius:8px;line-height:0;">
                                    @if ($restaurant->usdt_address)
                                        {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(130)->margin(1)->generate($restaurant->usdt_address) !!}
                                    @endif
                                </div>
                                <div><small class="text-muted" id="usdt_qr_hint" style="display:none;">{{ translate('地址已修改，保存后二维码会更新') }}</small></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-2">
                <div class="card-body text-right">
                    <button type="submit" class="btn btn-primary">{{ translate('保存收款信息') }}</button>
                </div>
            </div>
        </form>
        <script>
        (function () {
            var DEF = @json(translate('未选择文件'));
            var SEL = @json(translate('新选择的收款码 (点保存后生效)'));
            // 选图后: 直接把"当前收款码"那张图替换成所选图(原地预览), 不另起预览框
            function wire(inputId, boxId, imgId, labelId, emptyId) {
                var input = document.getElementById(inputId);
                if (!input) return;
                var cf = input.closest(".custom-file");
                var fileLabel = cf ? cf.querySelector(".custom-file-label") : null;
                var box = document.getElementById(boxId);
                var img = document.getElementById(imgId);
                var label = document.getElementById(labelId);
                var empty = document.getElementById(emptyId);
                input.addEventListener("change", function () {
                    if (input.files && input.files[0]) {
                        if (fileLabel) fileLabel.textContent = input.files[0].name;
                        if (img) { img.src = URL.createObjectURL(input.files[0]); img.style.display = "inline-block"; }
                        if (box) box.style.display = "";
                        if (label) label.textContent = SEL;
                        if (empty) empty.style.display = "none";
                    } else {
                        if (fileLabel) fileLabel.textContent = DEF;
                    }
                });
            }
            wire("rmb_qr_image", "rmb_qr_box", "rmb_qr_current", "rmb_qr_label", "rmb_qr_empty");
            // USDT: 改地址时提示二维码需保存后更新(二维码服务端按已存地址生成)
            var usdt = document.querySelector('input[name="usdt_address"]');
            var usdtHint = document.getElementById("usdt_qr_hint");
            if (usdt && usdtHint) {
                var orig = usdt.value;
                usdt.addEventListener("input", function () {
                    usdtHint.style.display = (usdt.value !== orig) ? "block" : "none";
                });
            }
        })();
        </script>

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
                    <div class="row g-3 align-items-start">
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('1 元人民币 = ? ֏') }}</label>
                            <input type="number" step="0.01" min="1" max="100000" name="nezha_rate_cny_to_amd"
                                   class="form-control" value="{{ $cnyToAmd ?? 55 }}" placeholder="55" required>
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('1 美元 = ? ֏') }}</label>
                            <input type="number" step="0.01" min="1" max="100000" name="nezha_rate_usd_to_amd"
                                   class="form-control" value="{{ $usdToAmd ?? 400 }}" placeholder="400" required>
                        </div>
                        <div class="col-md-4">
                            <label class="input-label">{{ translate('1 美元 = ? 元 (自动算出, 不可填)') }}</label>
                            <input type="text" id="usd-to-rmb-derived" class="form-control" readonly
                                   value="{{ $rmbRate ?? 7.1 }}" style="background:#f1f3f5;cursor:not-allowed">
                            <small class="text-muted">{{ translate('= (1美元=?֏) ÷ (1人民币=?֏), 自动保持自洽') }}</small>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-3">
                        <div class="text-muted small">
                            {{ translate('示例: 1元=55֏、1美元=400֏ → 5500֏ 订单 ≈ ¥100 / $13.75') }}
                        </div>
                        <button type="submit" class="btn btn-primary">{{ translate('更新汇率') }}</button>
                    </div>
                    @php
                        $__fxSync = json_decode(\App\Models\BusinessSetting::where('key','nezha_fx_last_sync')->value('value') ?? '', true);
                    @endphp
                    @if(is_array($__fxSync) && !empty($__fxSync['status']) && $__fxSync['status'] !== 'dry-run')
                        <div class="mt-2 small {{ $__fxSync['status']==='skipped' ? 'text-danger font-weight-bold' : 'text-muted' }}">
                            @if($__fxSync['status']==='skipped')
                                ⚠️ {{ translate('每周自动对齐已暂停, 等你确认') }}：{{ $__fxSync['detail'] ?? '' }}
                            @else
                                🕒 {{ translate('上次自动对齐') }}：{{ $__fxSync['at'] ?? '' }} · {{ translate('每天自动按市场中间价更新') }}
                            @endif
                        </div>
                    @endif
                </form>
                <script>
                (function(){
                    var cnyInput = document.querySelector('input[name="nezha_rate_cny_to_amd"]');
                    var usdInput = document.querySelector('input[name="nezha_rate_usd_to_amd"]');
                    var out = document.getElementById('usd-to-rmb-derived');
                    if(!cnyInput || !usdInput || !out) return;
                    function recalc(){
                        var c = parseFloat(cnyInput.value), u = parseFloat(usdInput.value);
                        out.value = (isFinite(c) && isFinite(u) && c > 0) ? (Math.round(u / c * 10000) / 10000) : '';
                    }
                    cnyInput.addEventListener('input', recalc);
                    usdInput.addEventListener('input', recalc);
                })();
                </script>
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
                    <small class="text-muted">{{ translate('提示: 充值金额按德拉姆(֏)填写; 仅记录到本系统预存佣金账本, 实际收款请商家另行线下转账给平台。') }}</small>
                </form>
                {{-- 换算小工具: 三币种联动 (德拉姆 ֏ ⇄ 人民币 ¥ ⇄ 美元 $), 填任一框自动算另两框, 不提交 --}}
                <div class="mt-3 p-3 bg-light rounded">
                    <small class="text-muted d-block mb-2"><i class="tio-calculator"></i> {{ translate('换算小工具 (不提交, 仅帮你换算): 在任意一个框输入金额, 另两个自动换算') }}</small>
                    <div class="row g-2 align-items-center">
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend"><span class="input-group-text">֏</span></div>
                                <input type="number" step="0.01" min="0" id="conv-amd" class="form-control" style="max-width:140px" placeholder="{{ translate('德拉姆') }}">
                            </div>
                        </div>
                        <div class="col-auto text-muted">=</div>
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend"><span class="input-group-text">¥</span></div>
                                <input type="number" step="0.01" min="0" id="conv-cny" class="form-control" style="max-width:140px" placeholder="{{ translate('人民币') }}">
                            </div>
                        </div>
                        <div class="col-auto text-muted">=</div>
                        <div class="col-auto">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                <input type="number" step="0.01" min="0" id="conv-usd" class="form-control" style="max-width:140px" placeholder="{{ translate('美元') }}">
                            </div>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-2">{{ translate('按当前平台汇率换算:') }} 1¥ = {{ $cnyToAmd ?? 55 }}֏, 1$ = {{ $usdToAmd ?? 400 }}֏</small>
                </div>
                <script>
                (function(){
                    var cnyToAmd = parseFloat('{{ $cnyToAmd ?? 55 }}') || 55;   // 1 人民币 = ? 德拉姆
                    var usdToAmd = parseFloat('{{ $usdToAmd ?? 400 }}') || 400;  // 1 美元   = ? 德拉姆
                    var amd = document.getElementById('conv-amd');
                    var cny = document.getElementById('conv-cny');
                    var usd = document.getElementById('conv-usd');
                    if(!amd || !cny || !usd) return;
                    var lock = false;
                    function fmt(v){ return (!isFinite(v) || v <= 0) ? '' : (Math.round(v * 100) / 100); }
                    function fromAmd(){
                        if(lock) return; lock = true;
                        var v = parseFloat(amd.value);
                        if(isFinite(v) && v > 0){
                            cny.value = fmt(v / cnyToAmd);
                            usd.value = fmt(v / usdToAmd);
                        } else { cny.value = ''; usd.value = ''; }
                        lock = false;
                    }
                    function fromCny(){
                        if(lock) return; lock = true;
                        var v = parseFloat(cny.value);
                        if(isFinite(v) && v > 0){
                            var a = v * cnyToAmd;
                            amd.value = fmt(a);
                            usd.value = fmt(a / usdToAmd);
                        } else { amd.value = ''; usd.value = ''; }
                        lock = false;
                    }
                    function fromUsd(){
                        if(lock) return; lock = true;
                        var v = parseFloat(usd.value);
                        if(isFinite(v) && v > 0){
                            var a = v * usdToAmd;
                            amd.value = fmt(a);
                            cny.value = fmt(a / cnyToAmd);
                        } else { amd.value = ''; cny.value = ''; }
                        lock = false;
                    }
                    amd.addEventListener('input', fromAmd);
                    cny.addEventListener('input', fromCny);
                    usd.addEventListener('input', fromUsd);
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
