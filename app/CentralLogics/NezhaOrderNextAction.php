<?php

namespace App\CentralLogics;

/**
 * 哪吒 P1b-B: 商家订单「当前唯一主操作」决策 —— 单一真相源(仅计算, 不产生副作用)。
 *
 * 从 order-view.blade M-04 决策块(原第 41-96 行)逐字抽取, 详情页 / 作业台(未来) 共用同一 decide(),
 * 保证同一单永远给出同一主操作。复用现有路由, 不新增路由 / 不碰控制器 / 不碰状态机 —— 纯 L3 呈现。
 *
 * 唯一有意改动(裁决①·采纳并强化): pending 且 payment=offline 但顾客【未传凭证】的单,
 * 由原误导的「确认收款·接单」(kind=link→confirmed) 改为 kind='wait'·visible=false(无主CTA)+ 等凭证提示。
 * 依据: 无凭证单商家无款可确认, 正规 confirm-offline-payment 幂等门也要求凭证行(源码核实见 fable plan「Opus 核验补丁」)。
 * 不碰 confirm_offline_payment / payment_status / L1。
 *
 * 返回结构与原 $nzPrimary 完全一致(visible/kind/label/route/method/confirm/data/note),
 * 部分分支附加 combined_yandex / yandex_route(与原块一致)。
 */
class NezhaOrderNextAction
{
    protected static function none(): array
    {
        return ['visible' => false, 'kind' => null, 'label' => null, 'route' => null, 'method' => null, 'confirm' => null, 'data' => [], 'note' => null];
    }

    /**
     * @param  \App\Models\Order  $order  需已载入 offline_payments / restaurant 关系
     * @param  mixed  $maxProcessingTime  C(开始备餐)分支的 processing-time(order-view 传入其视图变量; 其它调用方可省)
     */
    public static function decide($order, $maxProcessingTime = null): array
    {
        $os = $order->order_status;
        $type = $order->order_type;
        $offline = $order->payment_method == 'offline_payment';
        $offPending = $offline && $order->offline_payments && $order->offline_payments->status == 'pending';
        $selfDelivery = ($order->restaurant->sub_self_delivery ?? 0) == 1;
        $odv = (bool) \App\CentralLogics\Helpers::get_business_data('order_delivery_verification');
        $terminalish = in_array($os, ['delivered', 'canceled', 'refunded', 'failed', 'refund_requested', 'refund_request_canceled'], true);

        if ($terminalish) {
            return self::none();
        }

        if ($os == 'pending') {
            if ($offPending) {
                // A 离线待核验(有凭证) → 确认收款(三合一: 收款+接单+开始备餐)
                return ['visible' => true, 'kind' => 'form', 'label' => '确认收款（我已收到顾客付款）',
                    'route' => route('vendor.order.confirm-offline-payment', ['id' => $order['id']]), 'method' => 'PUT',
                    'confirm' => '请确认：您已在自己的账户收到本单顾客的付款？确认后将通知顾客并可开始出餐。', 'data' => [], 'note' => null];
            }
            if ($offline) {
                // 裁决①: 无凭证离线 pending → 无主CTA + 等凭证提示(替代原误导的「确认收款·接单」)
                return ['visible' => false, 'kind' => 'wait', 'label' => null, 'route' => null, 'method' => null,
                    'confirm' => null, 'data' => [], 'note' => '顾客尚未上传付款凭证，等顾客提交凭证后再确认收款'];
            }
            // B 真·非离线 pending(哪吒当前无此支付路径, 保留原线上行为『确认收款·接单』)
            return ['visible' => true, 'kind' => 'link', 'label' => '确认收款·接单',
                'route' => route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'confirmed']), 'method' => null,
                'confirm' => null, 'data' => ['message' => translate('Change status to confirmed ?')], 'note' => null];
        }

        if (in_array($os, ['confirmed', 'accepted'], true)) {
            // C 开始备餐(accepted 与 confirmed 等价同分支)
            return ['visible' => true, 'kind' => 'link', 'label' => translate('messages.Proceed_for_cooking'),
                'route' => route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'processing']), 'method' => null,
                'confirm' => null, 'data' => ['message' => translate('Change status to cooking ?'), 'verification' => 'false', 'processing-time' => $maxProcessingTime], 'note' => null];
        }

        if ($os == 'processing') {
            if (in_array($type, ['take_away', 'dine_in'], true)) {
                // E 出餐完成待取 → handover
                return ['visible' => true, 'kind' => 'link', 'label' => translate('messages.make_ready_for_handover'),
                    'route' => route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'handover']), 'method' => null,
                    'confirm' => null, 'data' => ['message' => translate('Change status to ready for handover ?')], 'note' => null];
            }
            if ($type == 'delivery') {
                // D 配送单 → 出餐·标记配送中(合并 Yandex 面板)
                return ['visible' => true, 'kind' => 'form', 'label' => '出餐 · 标记配送中',
                    'route' => route('vendor.order.mark-dispatched', ['id' => $order['id']]), 'method' => 'PUT',
                    'confirm' => null, 'data' => [], 'note' => null,
                    'combined_yandex' => true, 'yandex_route' => route('vendor.order.set-yandex-delivery', ['id' => $order['id']])];
            }
            return self::none();
        }

        if ($os == 'handover') {
            if (in_array($type, ['dine_in', 'take_away'], true) || $selfDelivery) {
                // F 已送达/完成(门槛=餐厅级 sub_self_delivery)
                return ['visible' => true, 'kind' => 'link', 'label' => ($type == 'dine_in' ? translate('messages.Make_Completed') : translate('messages.make_delivered')),
                    'route' => route('vendor.order.status', ['id' => $order['id'], 'order_status' => 'delivered']), 'method' => null,
                    'confirm' => null, 'data' => ['message' => translate('Change status to delivered (payment status will be paid if not) ?'), 'verification' => ($odv ? 'true' : 'false')], 'note' => null];
            }
            if ($type == 'delivery') {
                // D' 配送单 handover → 出餐·标记配送中(合并 Yandex 面板)
                return ['visible' => true, 'kind' => 'form', 'label' => '出餐 · 标记配送中',
                    'route' => route('vendor.order.mark-dispatched', ['id' => $order['id']]), 'method' => 'PUT',
                    'confirm' => null, 'data' => [], 'note' => null,
                    'combined_yandex' => true, 'yandex_route' => route('vendor.order.set-yandex-delivery', ['id' => $order['id']])];
            }
            return self::none();
        }

        if ($os == 'picked_up') {
            // G 配送中: 信息条(含预估完成 + 上提「已送达」按钮)
            $autoHours = (int) (\App\CentralLogics\Helpers::get_business_data('nezha_auto_finalize_handover_hours') ?: 3);
            return ['visible' => true, 'kind' => 'info', 'label' => '标记为「已送达」',
                'route' => route('vendor.order.mark-delivered', ['id' => $order['id']]), 'method' => 'PUT',
                'confirm' => '确认本单已送达顾客？确认后订单完成、不可撤销。', 'data' => [],
                'note' => '配送中 · ' . ($order->yandex_tracking_url ? '顾客可追踪骑手位置' : '顾客已看到配送状态') . '。约 ' . $autoHours . ' 小时无确认将自动完成',
                'combined_yandex' => false];
        }

        return self::none();
    }
}
