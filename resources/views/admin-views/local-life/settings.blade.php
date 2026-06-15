@extends('layouts.admin.app')
@section('title', '本地生活 · 护栏与文案设置')
@push('css_or_js')
@endpush

@section('content')
<div class="content container-fluid">

    <div class="d-flex align-items-center justify-content-between mb-2 mt-2 flex-wrap gap-2">
        <h2 class="h3 mb-0">本地生活 · 护栏与文案设置</h2>
        <a href="{{ route('admin.local-life.list') }}" class="btn btn--secondary btn-sm">返回帖子列表</a>
    </div>

    <form action="{{ route('admin.local-life.settings.save') }}" method="post">
        @csrf

        {{-- 违禁词 --}}
        <div class="card mt-2">
            <div class="card-header py-2"><h5 class="card-title mb-0">违禁词过滤</h5></div>
            <div class="card-body">
                <p class="text-muted mb-2" style="font-size:13px;">
                    发帖（及后台录入）会扫描标题+描述+联系方式，命中任一词即<strong>直接拒绝</strong>（提示"内容含违规词"，不告诉对方是哪个词）。
                    <br>一行一个词，或用逗号分隔。<strong>只填确需拦截的实体词</strong>（如"赌博""刷单"），别填"租""出""微信"这类高频字以免误伤正常帖。
                    <br><span class="text-danger">留空</span>则使用系统内置默认词库（涉黄/赌博/诈骗洗钱/毒品违禁/外站引流）。
                </p>
                <textarea name="locallife_banned_words" class="form-control" rows="8"
                          placeholder="留空=使用系统默认词库">{{ $s['locallife_banned_words'] ?? '' }}</textarea>
            </div>
        </div>

        {{-- 免责短提示 --}}
        <div class="card mt-2">
            <div class="card-header py-2"><h5 class="card-title mb-0">免责短提示（列表 / 详情底部常驻）</h5></div>
            <div class="card-body">
                <p class="text-muted mb-2" style="font-size:13px;">顾客端「本地生活」列表与详情页底部常驻的灰色小字。建议简短一两句。</p>
                <textarea name="locallife_disclaimer" class="form-control" rows="3"
                          placeholder="留空=使用系统默认免责提示">{{ $s['locallife_disclaimer'] ?? '' }}</textarea>
            </div>
        </div>

        {{-- 规则全文 --}}
        <div class="card mt-2">
            <div class="card-header py-2"><h5 class="card-title mb-0">《本地生活信息发布规则》全文</h5></div>
            <div class="card-body">
                <div class="alert alert-warning py-2" style="font-size:13px;">
                    ⚠️ 这是<strong>面向用户的法律文本</strong>，发帖时顾客需勾选同意、详情页可查看。改动须慎重，重大修改建议先请律师过目。
                    正本同时存放于后端 <code>docs/legal/local-life-terms.md</code>，<strong>这里改了请同步那份正本</strong>，保持一致。
                </div>
                <textarea name="locallife_terms" class="form-control" rows="16" style="font-family:inherit;"
                          placeholder="留空=使用系统默认规则全文">{{ $s['locallife_terms'] ?? '' }}</textarea>
            </div>
        </div>

        {{-- 反滥用阈值 --}}
        <div class="card mt-2">
            <div class="card-header py-2"><h5 class="card-title mb-0">反滥用阈值</h5></div>
            <div class="card-body">
                <p class="text-muted mb-3" style="font-size:13px;">控制顾客发帖/举报的频率，防刷屏。留空则保持当前值（括号内为系统默认）。</p>
                <div class="row">
                    <div class="form-group col-md-4">
                        <label class="input-label">每位顾客每日发帖上限（默认 5）</label>
                        <input type="number" name="locallife_ugc_daily_limit" class="form-control" min="1" max="100"
                               value="{{ $s['locallife_ugc_daily_limit'] ?? '' }}" placeholder="5">
                    </div>
                    <div class="form-group col-md-4">
                        <label class="input-label">每位顾客每日举报上限（默认 20）</label>
                        <input type="number" name="locallife_report_daily_limit" class="form-control" min="1" max="500"
                               value="{{ $s['locallife_report_daily_limit'] ?? '' }}" placeholder="20">
                    </div>
                    <div class="form-group col-md-4">
                        <label class="input-label">两次发帖最小间隔（秒，默认 60；填 0 = 关闭此限制）</label>
                        <input type="number" name="locallife_ugc_min_interval_sec" class="form-control" min="0" max="3600"
                               value="{{ $s['locallife_ugc_min_interval_sec'] ?? '' }}" placeholder="60">
                    </div>
                </div>
            </div>
        </div>

        {{-- 举报记录保留期 --}}
        <div class="card mt-2">
            <div class="card-header py-2"><h5 class="card-title mb-0">举报记录 PII 保留期</h5></div>
            <div class="card-body">
                <p class="text-muted mb-3" style="font-size:13px;">
                    顾客举报里的「补充说明」（detail）可能含联系方式等个人信息（PII）。计划任务每日 03:40 会把超过保留期的举报<strong>补充说明清空</strong>（置空），但<strong>保留举报本身、理由、处理状态</strong>供运营审计。留空则保持当前值（默认 180 天）。
                </p>
                <div class="row">
                    <div class="form-group col-md-4">
                        <label class="input-label">举报补充说明保留天数（默认 180）</label>
                        <input type="number" name="locallife_report_retention_days" class="form-control" min="1" max="3650"
                               value="{{ $s['locallife_report_retention_days'] ?? '' }}" placeholder="180">
                    </div>
                </div>
            </div>
        </div>

        {{-- 多语言版本（选填，留空=回退中文） --}}
        <div class="card mt-2">
            <div class="card-header py-2"><h5 class="card-title mb-0">免责短提示 · 多语言版本（选填）</h5></div>
            <div class="card-body">
                <p class="text-muted mb-2" style="font-size:13px;">
                    对应语言界面的顾客会看到这里的版本；<strong>留空则回退中文</strong>。
                    <span class="text-danger">⚠️ 亚美尼亚语须经母语法律译者审校后再填，未审校请留空。</span>
                </p>
                <label class="input-label">English（英语）</label>
                <textarea name="locallife_disclaimer_en" class="form-control mb-2" rows="2" placeholder="留空=回退中文">{{ $s['locallife_disclaimer_en'] ?? '' }}</textarea>
                <label class="input-label">Русский（俄语）</label>
                <textarea name="locallife_disclaimer_ru" class="form-control mb-2" rows="2" placeholder="留空=回退中文">{{ $s['locallife_disclaimer_ru'] ?? '' }}</textarea>
                <label class="input-label">Հայերեն（亚美尼亚语，须律师审校）</label>
                <textarea name="locallife_disclaimer_hy" class="form-control" rows="2" placeholder="留空=回退中文（未经审校请勿填）">{{ $s['locallife_disclaimer_hy'] ?? '' }}</textarea>
            </div>
        </div>

        <div class="card mt-2">
            <div class="card-header py-2"><h5 class="card-title mb-0">《信息发布规则》全文 · 多语言版本（选填）</h5></div>
            <div class="card-body">
                <p class="text-muted mb-2" style="font-size:13px;">
                    <strong>留空则回退中文。</strong>这是面向用户的法律文本，填入前请确保译文已经律师/母语法律译者审校；正本同步至 <code>docs/legal/local-life-terms.md</code>。
                </p>
                <label class="input-label">English（英语）</label>
                <textarea name="locallife_terms_en" class="form-control mb-2" rows="6" placeholder="留空=回退中文">{{ $s['locallife_terms_en'] ?? '' }}</textarea>
                <label class="input-label">Русский（俄语）</label>
                <textarea name="locallife_terms_ru" class="form-control mb-2" rows="6" placeholder="留空=回退中文">{{ $s['locallife_terms_ru'] ?? '' }}</textarea>
                <label class="input-label">Հայերեն（亚美尼亚语，须律师审校）</label>
                <textarea name="locallife_terms_hy" class="form-control" rows="6" placeholder="留空=回退中文（未经审校请勿填）">{{ $s['locallife_terms_hy'] ?? '' }}</textarea>
            </div>
        </div>

        {{-- 《个人数据处理通知》PII 同意 --}}
        <div class="card mt-2 border-danger">
            <div class="card-header py-2"><h5 class="card-title mb-0 text-danger">《个人数据处理通知与同意》（PII · 慎用）</h5></div>
            <div class="card-body">
                <div class="alert alert-danger py-2" style="font-size:13px;">
                    🔴 <strong>这是新的「同意采集」环节，开启即开始收集个人数据处理同意，属对外法律行为。开启前必须满足：</strong>
                    <ul class="mb-1 mt-1">
                        <li>已有<strong>亚美尼亚注册实体</strong>，并在文案里写明「数据控制者＝公司全称＋注册地址」；</li>
                        <li>文案已经<strong>当地律师审校</strong>（亚美尼亚语须母语法律译者审校）。</li>
                    </ul>
                    未满足以上条件请<strong>不要勾选下方开关、不要填写正文</strong>。开关关闭时，发帖流程与今天完全一致（不出现第二个勾选项）。
                </div>
                <div class="form-group">
                    <label class="d-flex align-items-center" style="gap:8px;cursor:pointer;">
                        <input type="checkbox" name="locallife_pii_consent_enabled" value="1"
                               {{ (string)($s['locallife_pii_consent_enabled'] ?? '0') === '1' ? 'checked' : '' }}>
                        <span class="text-danger" style="font-weight:600;">开启：发帖时强制勾选《个人数据处理通知》同意（需正文非空才真正生效）</span>
                    </label>
                </div>
                <label class="input-label">通知正文 · 中文</label>
                <textarea name="locallife_pii_notice" class="form-control mb-2" rows="6" placeholder="留空=不展示（即使开关打开，正文空也不会强制勾选）">{{ $s['locallife_pii_notice'] ?? '' }}</textarea>
                <label class="input-label">English（英语）</label>
                <textarea name="locallife_pii_notice_en" class="form-control mb-2" rows="4" placeholder="留空=回退中文">{{ $s['locallife_pii_notice_en'] ?? '' }}</textarea>
                <label class="input-label">Русский（俄语）</label>
                <textarea name="locallife_pii_notice_ru" class="form-control mb-2" rows="4" placeholder="留空=回退中文">{{ $s['locallife_pii_notice_ru'] ?? '' }}</textarea>
                <label class="input-label">Հայերեն（亚美尼亚语，须律师审校）</label>
                <textarea name="locallife_pii_notice_hy" class="form-control" rows="4" placeholder="留空=回退中文（未经审校请勿填）">{{ $s['locallife_pii_notice_hy'] ?? '' }}</textarea>
            </div>
        </div>

        <div class="d-flex justify-content-end mt-3 mb-4">
            <button type="submit" class="btn btn--primary">保存设置</button>
        </div>
    </form>
</div>
@endsection
