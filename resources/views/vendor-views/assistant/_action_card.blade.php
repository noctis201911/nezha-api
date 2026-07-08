{{-- 动作确认卡（安全流：AI 提议 → 商家点确认才执行）。三态：pending 琥珀 / done 绿 / cancelled 灰。
     确认/取消走 data-nz-ajax（全站 _nz_ui_kit·不落屏）→ ask() 执行/回写 → back() → 整页从库回渲染。
     🔴 草稿在 payload（服务端持久）；确认端点用 restaurant_id + status=pending 作用域防越权/重放。 --}}
@php
    /** @var \App\Models\NezhaAssistantMessage $m */
    $type = $m->action_type;
    $p = is_array($m->payload) ? $m->payload : [];
    $st = $m->status ?: 'pending';
    $fmt = function ($v) {
        $f = (float) $v;
        return floor($f) == $f ? number_format($f, 0) : rtrim(rtrim(number_format($f, 2), '0'), '.');
    };
    $titles = ['pause' => '暂停接单', 'resume' => '恢复营业', 'price' => '修改菜品价格', 'feedback' => '提交问题反馈'];
    $title = $titles[$type] ?? '操作';
    if ($type === 'price') {
        $desc = e($p['food_name'] ?? '菜品') . '：<s>֏' . $fmt($p['old_price'] ?? 0) . '</s> → <b>֏' . $fmt($p['new_price'] ?? 0) . '</b>（顾客端立即生效）';
    } elseif ($type === 'pause') {
        $desc = '顾客端显示「休息中」，进行中订单不受影响。';
    } elseif ($type === 'resume') {
        $desc = '顾客端恢复正常下单。';
    } elseif ($type === 'feedback') {
        $tl = \App\Models\VendorFeedback::TYPE_LABELS[$p['type'] ?? 'other'] ?? ($p['type'] ?? '其他');
        $desc = '【' . e($tl) . '】' . e($p['subject'] ?? '');
    } else {
        $desc = '';
    }
    $okLabels = ['pause' => '确认暂停接单', 'resume' => '确认恢复营业', 'price' => '确认改价', 'feedback' => '确认提交给平台'];
    $okLabel = $okLabels[$type] ?? '确认';
    $doneAt = $m->updated_at ?? $m->created_at;
@endphp
<div class="nzma-acard nzma-acard-{{ $st }}">
    <div class="nzma-cap">AI 建议的操作
        @if ($st === 'done')
            <span class="nzma-st done">✓ 已执行 · {{ optional($doneAt)->format('n月j日 H:i') }}</span>
        @elseif ($st === 'cancelled')
            <span class="nzma-st cancel">已取消</span>
        @else
            <span class="nzma-st pend">待您确认</span>
        @endif
    </div>
    <div class="nzma-atitle">{{ $title }}</div>
    <div class="nzma-adesc">{!! $desc !!}</div>
    @if ($st === 'pending')
        <div class="nzma-aops">
            <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" class="nzma-aform" data-nz-ajax data-nz-ok-toast="已执行">
                @csrf
                <input type="hidden" name="confirm_action" value="{{ $type }}">
                <input type="hidden" name="msg_id" value="{{ $m->id }}">
                <button type="submit" class="nzma-ok">{{ $okLabel }}</button>
            </form>
            <form method="POST" action="{{ route('vendor.nezha-assistant.ask') }}" class="nzma-aform" data-nz-ajax data-nz-ok-toast="已取消">
                @csrf
                <input type="hidden" name="cancel_action" value="1">
                <input type="hidden" name="msg_id" value="{{ $m->id }}">
                <button type="submit" class="nzma-no">取消</button>
            </form>
        </div>
    @endif
</div>
