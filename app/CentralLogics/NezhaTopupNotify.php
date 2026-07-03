<?php

namespace App\CentralLogics;

use App\Models\NezhaTopupRequest;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Log;

/**
 * 哪吒 自助充值/押金退款 (A3 · S4) — 审核结果通知 单一落点.
 *
 * 复用既有 vendor 通知设施(不造第二套):
 *   - TG   : Helpers::sendTelegramToRestaurant(商家绑定 restaurant.telegram_chat_id·未绑=no-op·异步/兜底同步).
 *   - 站内信: Vendor\DashboardController::restaurant_data 订单轮询把 pollResults() 喂给顶栏 nzBell
 *            (客户端 seen-set 去重·在场对账中心页自动清红点不重复弹).
 *
 * 幂等: TG 在控制器终态转换(事务 commit)后调一次; 重复审核被状态门挡回不再发. 站内信是拉取式(客户端去重)无双发概念.
 * 全 dormant: 自助充值总闸 nezha_topup_status 关时 pollResults() 直接空(热轮询零开销); TG 仅在真有审核动作时才发.
 */
class NezhaTopupNotify
{
    private const ACCOUNT_LABELS = [
        'deposit'   => '预存佣金',
        'guarantee' => '押金',
        'ad'        => '广告余额',
    ];

    /**
     * 审核结果 → 商家 Telegram(复用 sendTelegramToRestaurant·未绑定自动 no-op·失败静默降级不阻断主流程).
     * $event: topup_approved | topup_rejected | refund_approved | refund_paid | refund_rejected
     * 调用点: Admin\NezhaTopupController 对应动作在状态落库(commit)后调一次.
     */
    public static function tgReviewResult(NezhaTopupRequest $req, string $event): void
    {
        try {
            $restaurant = $req->restaurant ?: Restaurant::where('vendor_id', $req->vendor_id)->first();
            if (!$restaurant) {
                return;
            }
            $text = self::buildText($req, $event);
            if ($text === '') {
                return;
            }
            Helpers::sendTelegramToRestaurant($restaurant, $text);
        } catch (\Throwable $e) {
            Log::warning('nezha topup notify tg failed #' . ($req->id ?? '?') . ': ' . $e->getMessage());
        }
    }

    private static function accountLabel(?string $type): string
    {
        return self::ACCOUNT_LABELS[$type] ?? (string) $type;
    }

    private static function amd($v): string
    {
        return number_format((float) $v, 0) . '֏';
    }

    /** 结果金额: 退款/入账优先取实际入账额, 回退申请额. */
    private static function amount(NezhaTopupRequest $r): string
    {
        return self::amd($r->amount_credited ?: $r->amount_claimed);
    }

    private static function buildText(NezhaTopupRequest $req, string $event): string
    {
        $acct = self::accountLabel($req->account_type);
        switch ($event) {
            case 'topup_approved':
                return "✅ 哪吒 · 充值已入账\n账户：{$acct}\n入账金额：" . self::amount($req)
                    . "\n您的余额已更新，可在商家后台「对账中心」查看。";
            case 'topup_rejected':
                return "⚠️ 哪吒 · 充值申请被打回\n账户：{$acct}"
                    . ($req->reason ? "\n原因：{$req->reason}" : '')
                    . "\n请核对后在「对账中心」重新提交。";
            case 'refund_approved':
                $when = $req->scheduled_pay_at
                    ? "\n将于 " . $req->scheduled_pay_at->format('Y-m-d H:i') . " 后为您放款至 KYC 收款账户。"
                    : "\n平台将尽快为您放款至 KYC 收款账户。";
                return "✅ 哪吒 · 押金退款已审批\n金额：" . self::amount($req) . $when;
            case 'refund_paid':
                return "✅ 哪吒 · 押金退款已放款\n金额：" . self::amount($req)
                    . "\n已退回您的 KYC 收款账户，押金余额已冲减。";
            case 'refund_rejected':
                return "⚠️ 哪吒 · 押金退款被打回"
                    . ($req->reason ? "\n原因：{$req->reason}" : '')
                    . "\n如有疑问请联系平台客服。";
        }
        return '';
    }

    /**
     * 站内信数据源: 该 vendor 近 7 天已审阅的充值/退款结果, 供订单轮询喂顶栏 nzBell.
     * - 自助充值总闸关(dormant) → 直接空数组(热路径零开销·连表都不查).
     * - id 前缀 tp + 复合 status 保证同一申请 approved→paid 各自成一条(客户端 seen-set 不会把"已放款"当"已审批"漏掉).
     * @return array<int,array{id:string,kind:string,status:string,label:string,href:string}>
     */
    public static function pollResults(int $vendorId): array
    {
        if ((int) Helpers::get_business_settings('nezha_topup_status') !== 1) {
            return [];
        }
        $rows = NezhaTopupRequest::where('vendor_id', $vendorId)
            ->whereIn('status', ['approved', 'rejected', 'paid'])
            ->where('updated_at', '>=', now()->subDays(7))
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['id', 'account_type', 'direction', 'status', 'amount_credited', 'amount_claimed']);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'     => 'tp' . $r->id . '_' . $r->status,
                'kind'   => $r->direction === 'refund' ? 'refund' : 'topup',
                'status' => (string) $r->status,
                'label'  => self::bellLabel($r),
                'href'   => self::bellHref($r),
            ];
        }
        return $out;
    }

    private static function bellLabel($r): string
    {
        $acct = self::accountLabel($r->account_type);
        $amt  = self::amd($r->amount_credited ?: $r->amount_claimed);
        if ($r->direction === 'refund') {
            if ($r->status === 'paid') {
                return "押金退款已放款 · {$amt}";
            }
            if ($r->status === 'approved') {
                return "押金退款已审批 · {$amt}";
            }
            return '押金退款被打回';
        }
        if ($r->status === 'approved') {
            return "{$acct}充值已入账 · {$amt}";
        }
        return "{$acct}充值被打回";
    }

    private static function bellHref($r): string
    {
        $account = in_array($r->account_type, ['deposit', 'guarantee', 'ad'], true) ? $r->account_type : 'deposit';
        try {
            // 相对 URL(absolute=false): 铃铛在 https 面板内渲染, 避免绝对 http:// 触发协议降级重定向.
            return route('vendor.nezha-deposit.index', ['account' => $account], false) . '#nz-topup-card';
        } catch (\Throwable $e) {
            return '#';
        }
    }
}
