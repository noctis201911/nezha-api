<?php

namespace App\CentralLogics;

/**
 * 哪吒 保证金(押金)健康四档 —— 单一真相源。
 *
 * 商家端「今日经营卡」(Vendor/DashboardController::nezha_today_summary) 与
 * 超管后台「商家列表」押金列(Admin/VendorController::list) 共用本判定, 防两处口径 drift。
 * 纯函数: 不查库(全部入参由调用方预取, 避免列表逐行 N+1), 只做分档。
 *
 * 四档(与 OrderController::nezha_commission_active / nezha_deposit_below_threshold 同源):
 *  - sample       未启用扣佣(总开关关 或 该店未开) 或 无扣佣历史 → 无从评估, 诚实显"未启用", 不伪造"充足"。
 *  - insufficient 已达下线阈值(balance <= 全局下线阈值), 抽佣开时可能无法接新单。
 *  - low          偏低(balance <= 商家自设低额告警阈值, 仅该店开启告警时)。
 *  - sufficient   充足。
 */
class NezhaDepositHealth
{
    /**
     * @param  object|array|null $restaurant      Restaurant(需 deposit_alert_enabled / deposit_alert_threshold)
     * @param  float             $balance         该店保证金余额(deposit_balance, 调用方预取)
     * @param  bool              $commissionActive该店抽佣是否激活(= 总开关开 且 该店开; 调用方按 nezha_commission_active 口径预取)
     * @param  float             $globalThreshold 全局下线阈值 nezha_min_deposit_threshold
     * @param  bool              $hasHistory      该店(vendor)是否有扣佣/充值流水(调用方批量预取, 防 N+1)
     * @return string sample|insufficient|low|sufficient
     */
    public static function tier($restaurant, float $balance, bool $commissionActive, float $globalThreshold, bool $hasHistory): string
    {
        // 抽佣未激活 或 无扣佣历史 → 无从评估健康
        if (! $commissionActive || ! $hasHistory) {
            return 'sample';
        }

        // 已达下线阈值(与接单闸 nezha_deposit_below_threshold 同源)
        if ($balance <= $globalThreshold) {
            return 'insufficient';
        }

        // 偏低线: 用商家自设的低额告警阈值(deposit_alert_threshold)作"偏低"区; 未设则只分充足/不足。
        $alertEnabled = is_array($restaurant)
            ? (bool) ($restaurant['deposit_alert_enabled'] ?? false)
            : (bool) ($restaurant->deposit_alert_enabled ?? false);
        $alertThreshold = is_array($restaurant)
            ? ($restaurant['deposit_alert_threshold'] ?? null)
            : ($restaurant->deposit_alert_threshold ?? null);

        if ($alertEnabled && $alertThreshold !== null && $balance <= (float) $alertThreshold) {
            return 'low';
        }

        return 'sufficient';
    }

    /** 四档 → 后台徽章文案 / Bootstrap 配色(与商家端 _today-summary.blade 同配色, 同状态同长相)。 */
    public static function badge(string $tier): array
    {
        $map = [
            'sufficient'   => ['label' => '充足',   'cls' => 'success',   'note' => ''],
            'low'          => ['label' => '偏低',   'cls' => 'warning',   'note' => '建议尽快充值'],
            'insufficient' => ['label' => '不足',   'cls' => 'danger',    'note' => '已达下线阈值，抽佣开启时可能无法接新单'],
            'sample'       => ['label' => '未启用', 'cls' => 'secondary', 'note' => '未启用佣金预存扣佣，无从评估'],
        ];
        return $map[$tier] ?? $map['sample'];
    }
}
