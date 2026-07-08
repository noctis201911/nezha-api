{{-- 单条消息：用户蓝气泡靠右 / 动作卡 / AI 灰白气泡靠左。content 用 pre-wrap 保留换行（见 _action_card / index CSS）。 --}}
@php /** @var \App\Models\NezhaAssistantMessage $m */ @endphp
@if ($m->role === 'user')
    <div class="nzma-row me"><div class="nzma-b-me">{{ $m->content }}</div></div>
@elseif ($m->action_type)
    @include('vendor-views.assistant._action_card', ['m' => $m])
@else
    <div class="nzma-row"><div class="nzma-b-ai">{{ $m->content }}</div></div>
@endif
