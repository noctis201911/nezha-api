<?php

namespace App\Console\Commands;

use App\CentralLogics\AdBalanceLogic;
use Illuminate\Console\Command;

/**
 * 哪吒商家广告竞价 T6 — ad_balance(广告子余额)充值入口(CLI).
 *
 * B2B 预付: 商家线下/对公付广告费 → 超管用本命令记入 ad_balance(沿用保证金充值同口径, INV-6 不碰顾客钱).
 * 实际原子/留痕逻辑已收敛到 App\CentralLogics\AdBalanceLogic::credit(单一真相源,
 * 与超管后台「广告充值」按钮共用, 防两处各写一遍导致 INV-1/INV-2 漂移).
 *
 * 用法: php artisan nezha:credit-ad-balance {vendor_id} {amount} [--note=...]
 */
class CreditAdBalance extends Command
{
    protected $signature = 'nezha:credit-ad-balance {vendor_id : 商家 vendor_id} {amount : 充值金额(֏, >0)} {--note= : 备注}';
    protected $description = '广告子余额(ad_balance)充值: B2B 预付记入, 只动 ad_balance 不碰 deposit_balance(INV-1)';

    public function handle(): int
    {
        $vendorId = (int) $this->argument('vendor_id');
        $amount   = (float) $this->argument('amount');
        $note     = $this->option('note') ?: '超管记录广告充值';

        try {
            $newBal = AdBalanceLogic::credit($vendorId, $amount, $note);
            $this->info("充值成功: vendor#{$vendorId} +{$amount}֏ → ad_balance={$newBal}֏ (deposit_balance 未动, INV-1)。");
            return self::SUCCESS;
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('充值失败: ' . $e->getMessage());
            info('[credit-ad-balance] failed vendor#' . $vendorId . ': ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
