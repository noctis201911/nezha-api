<?php

namespace App\CentralLogics;

use App\Models\RestaurantWallet;
use App\Models\RestaurantDepositTransaction;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒商家广告子余额(ad_balance)资金逻辑 — 单一真相源.
 *
 * 广告 CPC 计费的「充值(credit)」原子路径, 由 CLI(nezha:credit-ad-balance) 与
 * 超管后台「广告充值」按钮共用, 杜绝两处各写一遍导致不变量漂移.
 *
 * 落地不变量(见 docs/PLAN_ad_auction.md §2):
 * - INV-1 资金隔离: 只动 restaurant_wallets.ad_balance, 永不碰 deposit_balance.
 * - INV-2 原子: UPDATE ... SET ad_balance = ad_balance + ?, 不用 save() 整行写(避免锁外快照丢更新).
 * - INV-6 L2: ad_balance 走商家 B2B 预付, 不碰顾客钱.
 * 留痕: 写 restaurant_deposit_transactions type='ad_recharge'(与 deposit 'recharge' 区分, 不污染 deposit 对账).
 */
class AdBalanceLogic
{
    /**
     * 给某商家的广告子余额充值(B2B 预付记入). 原子 + 留痕, 返回充值后的 ad_balance.
     *
     * @param  int    $vendorId  商家 vendor_id (>0)
     * @param  float  $amount    充值金额 ֏ (>0)
     * @param  string $note      流水备注
     * @return RestaurantDepositTransaction  写入的 ad_recharge 流水行(balance_after=充值后 ad_balance)
     *
     * @throws \InvalidArgumentException 参数非法
     * @throws \RuntimeException         vendor 不存在
     */
    public static function credit(int $vendorId, float $amount, string $note = '超管记录广告充值'): RestaurantDepositTransaction
    {
        if ($vendorId <= 0 || $amount <= 0) {
            throw new \InvalidArgumentException('vendor_id 必须>0, amount 必须>0。');
        }

        $vendor = DB::table('vendors')->where('id', $vendorId)->first();
        if (!$vendor) {
            throw new \RuntimeException("vendor#{$vendorId} 不存在。");
        }
        $restaurant = DB::table('restaurants')->where('vendor_id', $vendorId)->first();

        return DB::transaction(function () use ($vendorId, $amount, $restaurant, $note) {
            // 确保钱包行存在(不存在则建, ad_balance/deposit_balance 默认 0); lockForUpdate 串行化同 vendor 并发建行
            $exists = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->exists();
            if (!$exists) {
                DB::table('restaurant_wallets')->insert([
                    'vendor_id'  => $vendorId,
                    'ad_balance' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // INV-2: 原子 credit, 不用 save() 整行写(避免锁外快照丢更新)
            DB::update(
                'UPDATE restaurant_wallets SET ad_balance = ad_balance + ?, updated_at = ? WHERE vendor_id = ?',
                [$amount, now(), $vendorId]
            );

            $newBal = (float) DB::table('restaurant_wallets')->where('vendor_id', $vendorId)->value('ad_balance');

            // 留痕: 与 deposit 'recharge' 区分, 不污染 deposit 对账(INV-1)
            $txId = DB::table('restaurant_deposit_transactions')->insertGetId([
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

            return RestaurantDepositTransaction::findOrFail($txId);
        });
    }
}
