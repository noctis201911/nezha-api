{{-- 会话消息列表：按日插入分组分隔线（今天/昨天/M月D日）。index() 与 history() 共用，单一渲染源。 --}}
@php $nzLastDay = null; @endphp
@foreach ($messages as $m)
    @php
        $nzLabel = null;
        $c = $m->created_at;
        $d = $c ? $c->format('Y-m-d') : null;
        if ($d !== $nzLastDay) {
            if ($c) {
                if ($c->isToday()) {
                    $nzLabel = '今天';
                } elseif ($c->isYesterday()) {
                    $nzLabel = '昨天';
                } else {
                    $nzLabel = $c->format('n月j日');
                }
            }
            $nzLastDay = $d;
        }
    @endphp
    @if ($nzLabel)
        <div class="nzma-day" data-day="{{ $nzLabel }}">{{ $nzLabel }}</div>
    @endif
    @include('vendor-views.assistant._msg_row', ['m' => $m])
@endforeach
