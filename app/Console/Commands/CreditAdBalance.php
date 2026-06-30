<?php

namespace App\Console\Commands;

use App\Models\RestaurantWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒商家广告竞价 T6 — ad_balance(广告子余额)充值入口.
 *
 * B2B 预付: 商家线下/对公付广告费 → 超管用本命令记入 ad_balance(沿用保证金充值同口径, INV-6 不碰顾客钱).
 * 资金隔离(INV-1): 只动 ad_balance, 不碰 deposit_balance → 广告余额与经营保证金彻底分离.
 * 原子(INV-2): 充值走 UPDATE ... SET ad_balance = ad_balance + ?, 不用 save() 整行写(避免丢更新).
 * 留痕: 写 restaurant_deposit_transactions type='ad_recharge'(与 deposit 的 'recharge' 区分; 不污染 deposit 对账).
 *
 * 第一期 = CLI 入口(供灰度充值/对账/死亡测试隔离验证用); 第二期超管后台「广告充值」按钮复用同逻辑.
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

        if ($vendorId <= 0 || $amount <= 0) {
            $this->error('vendor_id 必须>0, amount 必须>0。');
            return self::FAILURE;
        }

        $vendor = DB::table('vendors')->where('id', $vendorId)->first();
        if (!$vendor) {
            $this->error("vendor#{$vendorId} 不存在。");
            return self::FAILURE;
        }
        $restaurant = DB::table('restaurants')->where('vendor_id', $vendorId)->first();

        try {
            $newBal = DB::transaction(function () use ($vendorId, $amount, $restaurant, $note) {
                // 确保钱包行存在(不存在则建, ad_balance/deposit_balance 默认 0)
                $exists = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->exists();
                if (!$exists) {
                    DB::table('restaurant_wallets')->insert([
                        'vendor_id'   => $vendorId,
                        'ad_balance'  => 0,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }

                // 原子credit(INV-2: 不用 save() 整行写)
                DB::update(
                    'UPDATE restaurant_wallets SET ad_balance = ad_balance + ?, updated_at = ? WHERE vendor_id = ?',
                    [$amount, now(), $vendorId]
                );

                $newBal = (float) DB::table('restaurant_wallets')->where('vendor_id', $vendorId)->value('ad_balance');

                DB::table('restaurant_deposit_transactions')->insert([
                    'vendor_id'     => $vendorId,
                    'restaurant_id' => $restaurant?->id,
                    'order_id'      => null,
                    'type'          => 'ad_recharge',
                    'amount'        => $amount,
                    'commission'    => 0,
                    'balance_after' => $newBal,
                    'note'          => $note,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                return $newBal;
            });

            $this->info("充值成功: vendor#{$vendorId} +{$amount}֏ → ad_balance={$newBal}֏ (deposit_balance 未动, INV-1)。");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('充值失败: ' . $e->getMessage());
            info('[credit-ad-balance] failed vendor#' . $vendorId . ': ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
