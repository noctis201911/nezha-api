<?php

namespace App\CentralLogics;

use App\Models\Restaurant;
use App\Models\RestaurantWallet;
use App\Models\RestaurantDepositTransaction;

/**
 * 哪吒 商家 B2B 资金入账单一落点(ledger) — 预存佣金/押金/广告 三账户「记账正确性」的唯一 owner.
 *
 * 背景: 三账户共表 restaurant_deposit_transactions(靠 type 分) + restaurant_wallets(三子余额列).
 * 运营手动入账(超管后台按钮)与自助充值审核流(A3 · Admin\NezhaTopupController) 共用这三个方法,
 * 杜绝两处各写一遍导致余额/流水口径漂移(单一真相源, 不造第二套).
 *
 * 契约(三方法一致):
 *  - 调用方【必须已开启事务】(内部 lockForUpdate 需在事务内串行化同 vendor 并发);
 *  - 每方法只动自己账户的子余额列 + 写一条对应 type 流水, 绝不跨账户挪用(L1-8④ 资金隔离 / INV-1);
 *  - 一律返回写入的 RestaurantDepositTransaction 行(供审核流回填 nezha_topup_requests.transaction_id 做对账).
 *
 * 四处金额对账硬门(A3 DoD): 入账 amount == restaurant_deposit_transactions.amount == 子余额增量 == 对账中心展示.
 * 三方法均以传入 $amount 同时写 transaction.amount 与子余额增量, 等式由构造保证.
 *
 * 资金定性: 均为合法 B2B(平台向商家收的佣金预付 / 商家自有押金质押 / 广告服务预付), 非顾客资金,
 * 不涉二清(L1-1/L1-5). 押金红线 L1-8①: 法币-only(不收 USDT), 本 ledger 只管「持有 + 入账」方向;
 * 退还(中途退回/退出结算)另走 L1-8 护栏路径(NezhaOffboard / A3 S3-B), 不在本 ledger.
 */
class NezhaDepositLedger
{
    /**
     * 预存佣金入账: deposit_balance += $amount, 写 type=recharge 流水.
     * (从 Admin\NezhaDepositController::store_recharge 内联逻辑抽出, 行为不变.)
     * 调用方须已开启事务.
     */
    public static function recordRecharge(
        Restaurant $restaurant,
        float $amount,
        ?string $note,
        ?int $createdBy
    ): RestaurantDepositTransaction {
        $vendorId = $restaurant->vendor_id;

        // 行锁防并发(与扣佣同口径), 读最新余额后累加; 钱包不存在则先建再锁
        $wallet = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->first();
        if (!$wallet) {
            $wallet = new RestaurantWallet();
            $wallet->vendor_id = $vendorId;
            $wallet->deposit_balance = 0;
            $wallet->save();
            $wallet = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->first();
        }
        $newBalance = (float) ($wallet->deposit_balance ?? 0) + $amount;
        $wallet->deposit_balance = $newBalance;
        $wallet->save();

        return RestaurantDepositTransaction::create([
            'vendor_id'     => $vendorId,
            'restaurant_id' => $restaurant->id,
            'order_id'      => null,
            'type'          => 'recharge',
            'amount'        => $amount,
            'commission'    => 0,
            'balance_after' => $newBalance,
            'note'          => $note ?: '管理员记录充值',
            'created_by'    => $createdBy,
        ]);
    }

    /**
     * 押金缴纳入账: guarantee_balance += $amount(AMD 折算单值), 写 type=guarantee_deposit 流水.
     * (从 Admin\NezhaDepositController::recordGuaranteeDeposit 迁入, 行为不变.)
     * L1-8① 法币-only: currency 限 AMD/CNY(调用方已校验拒 USDT); L1-4 留痕: 记原币种/原额/回执号.
     * 只动 guarantee_balance, 绝不碰 deposit_balance/ad_balance(L1-8④ 资金隔离). 调用方须已开启事务.
     */
    public static function recordGuaranteeDeposit(
        Restaurant $restaurant,
        float $amount,
        string $currency,
        float $originalAmount,
        string $originalRef,
        ?string $note,
        ?int $createdBy
    ): RestaurantDepositTransaction {
        $vendorId = $restaurant->vendor_id;

        $wallet = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->first();
        if (!$wallet) {
            $wallet = new RestaurantWallet();
            $wallet->vendor_id = $vendorId;
            $wallet->guarantee_balance = 0;
            $wallet->save();
            $wallet = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->first();
        }
        $newBalance = (float) ($wallet->guarantee_balance ?? 0) + $amount;
        $wallet->guarantee_balance = $newBalance;
        $wallet->save();

        return RestaurantDepositTransaction::create([
            'vendor_id'       => $vendorId,
            'restaurant_id'   => $restaurant->id,
            'order_id'        => null,
            'type'            => 'guarantee_deposit',
            'amount'          => $amount,
            'commission'      => 0,
            'balance_after'   => $newBalance,
            'currency'        => $currency,
            'original_amount' => $originalAmount,
            'original_ref'    => $originalRef,
            'note'            => $note ?: '管理员记录押金缴纳',
            'created_by'      => $createdBy,
        ]);
    }

    /**
     * 广告子余额入账: ad_balance += $amount, 写 type=ad_recharge 流水.
     * 转调 AdBalanceLogic::credit(ad_balance 资金单一真相源 · INV-1/INV-2 原子隔离), 不重造第二套.
     * credit 自开事务, 嵌在调用方外层事务里走 savepoint —— 外层回滚则一并回滚, 原子性保持.
     * 返回写入的 ad_recharge 流水行(credit 已改为返回该行).
     */
    public static function recordAdRecharge(
        int $vendorId,
        float $amount,
        ?string $note
    ): RestaurantDepositTransaction {
        return AdBalanceLogic::credit($vendorId, $amount, $note ?: '自助充值广告余额入账');
    }
}
