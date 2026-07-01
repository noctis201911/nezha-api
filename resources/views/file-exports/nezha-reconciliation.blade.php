@php
    $labels = [
        'recharge'             => translate('充值'),
        'commission_deduction' => translate('扣佣'),
        'refund_reversal'      => translate('退款返还'),
        'advertisement_fee'    => translate('广告费(按天)'),
        'ad_recharge'          => translate('广告充值'),
        'ad_click_fee'         => translate('广告点击费'),
    ];
    $rc = $data['rate_cny'] > 0 ? $data['rate_cny'] : 55;
    $ru = $data['rate_usd'] > 0 ? $data['rate_usd'] : 400;
@endphp
<table>
    <thead>
        <tr><th colspan="6">{{ translate('对账单') }} · {{ $data['account_label'] }}</th></tr>
        <tr><th colspan="6">{{ translate('商家') }}: {{ $data['restaurant_name'] }}</th></tr>
        <tr><th colspan="6">{{ translate('区间') }}: {{ $data['from'] }} ~ {{ $data['to'] }}（{{ translate('币种') }}: ֏ AMD）</th></tr>
        <tr>
            <th colspan="3">{{ translate('期初余额') }}(֏): {{ number_format($data['opening'], 2) }}</th>
            <th colspan="3">{{ translate('期末余额') }}(֏): {{ number_format($data['closing'], 2) }}</th>
        </tr>
        <tr><th colspan="6"></th></tr>
        <tr>
            <th>{{ translate('时间') }}</th>
            <th>{{ translate('类型') }}</th>
            <th>{{ translate('变动') }}(֏)</th>
            <th>{{ translate('变动后余额') }}(֏)</th>
            <th>{{ translate('订单') }}</th>
            <th>{{ translate('备注') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($data['rows'] as $t)
            <tr>
                <td>{{ \Carbon\Carbon::parse($t->created_at)->format('Y-m-d H:i') }}</td>
                <td>{{ $labels[$t->type] ?? $t->type }}</td>
                <td>{{ number_format((float) $t->amount, 2) }}</td>
                <td>{{ number_format((float) $t->balance_after, 2) }}</td>
                <td>{{ $t->order_id ? ('#' . $t->order_id) : '' }}</td>
                <td>{{ $t->note }}</td>
            </tr>
        @empty
            <tr><td colspan="6">{{ translate('本区间暂无流水') }}</td></tr>
        @endforelse
    </tbody>
</table>
