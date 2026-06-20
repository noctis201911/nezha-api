<?php

namespace App\Console\Commands;

use App\Mail\DepositLowBalanceMail;
use App\Models\Advertisement;
use App\Models\BusinessSetting;
use App\Models\RestaurantDepositTransaction;
use App\Models\RestaurantWallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * 哪吒广告计费 T3 — 到投放起始日对「已通过 + 未扣费」广告从商家保证金扣全额(单价×天数).
 *
 * 资金定级 L2: 平台收自己的广告服务费, 从商家「预存保证金」扣, 不碰顾客钱, 非二清.
 * 新增保证金流水类型 advertisement_fee (与 commission_deduction / recharge / refund_reversal 并列).
 *
 * 选广告: status=approved 且 paid_at IS NULL 且 price>0 且
 *         start_date<=今天(埃里温时区) 且 end_date>=今天(已过期的不扣, 无服务可投).
 * 扣费: 事务内 lockForUpdate 防并发 lost-update(仿 OrderLogic 佣金扣款样板).
 *       余额充足 → deposit_balance -= price + 写流水 + is_paid=1 + paid_at=now + deposit_transaction_id.
 *       余额不足 → 跳过, 不置 is_paid, 发 DepositLowBalanceMail 提醒充值(不做宽限重试).
 * 幂等: paid_at IS NULL 为唯一防重闸; 事务内 lockForUpdate 重读 paid_at 二次确认, 命令重复跑只扣一次.
 *
 * 用法: php artisan nezha:charge-ad-on-start [--dry-run]
 * 调度: bootstrap/app.php->withSchedule (Laravel12 后 Kernel::schedule 已失效).
 */
class ChargeAdOnStart extends Command
{
    protected $signature = 'nezha:charge-ad-on-start {--dry-run : 只预览不扣费/不写库}';
    protected $description = '广告计费: 到投放起始日对已通过未扣费广告从保证金扣全额; 余额不足跳过并提醒充值';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $billing = (int) (BusinessSetting::where('key', 'nezha_ad_billing_status')->first()?->value ?? 0);
        if ($billing !== 1) {
            $this->info('广告计费总开关未开启 (nezha_ad_billing_status != 1), 跳过, 零扣费。');
            return self::SUCCESS;
        }

        $today = \Carbon\Carbon::now('Asia/Yerevan')->toDateString();

        $ads = Advertisement::where('status', 'approved')
            ->whereNull('paid_at')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->with('restaurant')
            ->get();

        $charged = 0; $lowBalance = 0; $skipped = 0; $failed = 0;

        foreach ($ads as $ad) {
            $price = (float) $ad->price;
            $vendorId = $ad->restaurant?->vendor_id;

            if (!$vendorId) {
                $skipped++;
                $this->line("跳过 广告#{$ad->id}: 餐馆无 vendor_id");
                continue;
            }

            if ($dry) {
                $bal = (float) (RestaurantWallet::where('vendor_id', $vendorId)->value('deposit_balance') ?? 0);
                $ok = $bal >= $price;
                $this->line('[dry] 广告#'.$ad->id.' 价格='.$price.' 余额='.round($bal, 2).' → '.($ok ? '可扣' : '余额不足'));
                $ok ? $charged++ : $lowBalance++;
                continue;
            }

            try {
                $result = DB::transaction(function () use ($ad, $price, $vendorId) {
                    // F-3 防并发 lost-update: 先锁钱包行读最新余额, 串行化同商家并发扣减(顺序: 钱包→广告, 与退费路径一致防死锁)。
                    $wallet = RestaurantWallet::where('vendor_id', $vendorId)->lockForUpdate()->first();
                    // 幂等二次确认: 事务内锁这条广告重读 paid_at, 已扣则放弃(防命令并发/重复跑二次扣)。
                    $fresh = Advertisement::where('id', $ad->id)->lockForUpdate()->first();
                    if (!$fresh || $fresh->paid_at !== null) {
                        return 'already';
                    }
                    $balance = (float) ($wallet?->deposit_balance ?? 0);
                    if (!$wallet || $balance < $price) {
                        return 'low';
                    }
                    $newBalance = round($balance - $price, 2);
                    $wallet->deposit_balance = $newBalance;
                    $wallet->save();

                    $txnId = RestaurantDepositTransaction::insertGetId([
                        'vendor_id'     => $vendorId,
                        'restaurant_id' => $ad->restaurant_id,
                        'order_id'      => null,
                        'type'          => 'advertisement_fee',
                        'amount'        => -1 * $price,
                        'commission'    => $price,
                        'balance_after' => $newBalance,
                        'note'          => '广告#'.$ad->id.' 推广费扣款',
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);

                    $fresh->is_paid = 1;
                    $fresh->paid_at = now();
                    $fresh->deposit_transaction_id = $txnId;
                    $fresh->save();

                    return 'charged';
                });

                if ($result === 'charged') {
                    $charged++;
                    $this->info('扣费成功 广告#'.$ad->id.' -'.$price);
                } elseif ($result === 'already') {
                    $skipped++;
                    $this->line('跳过(已扣/并发) 广告#'.$ad->id);
                } elseif ($result === 'low') {
                    $lowBalance++;
                    $bal = (float) (RestaurantWallet::where('vendor_id', $vendorId)->value('deposit_balance') ?? 0);
                    $this->line('余额不足 广告#'.$ad->id.' 需'.$price.' 现'.round($bal, 2).' → 发提醒, 不投放');
                    try {
                        $email = $ad->restaurant?->getRawOriginal('email') ?: $ad->restaurant?->deposit_alert_email;
                        if ($email) {
                            Mail::to($email)->send(new DepositLowBalanceMail($ad->restaurant?->name, $bal, $price));
                        }
                    } catch (\Throwable $e) {
                        info('[charge-ad-on-start] low-balance mail failed ad#'.$ad->id.': '.$e->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error('扣费异常 广告#'.$ad->id.': '.$e->getMessage());
                info('[charge-ad-on-start] failed ad#'.$ad->id.': '.$e->getMessage());
            }
        }

        $this->info(($dry ? '[dry-run] ' : '').'完成: 扣费'.$charged.' 余额不足'.$lowBalance.' 跳过'.$skipped.' 失败'.$failed);
        return self::SUCCESS;
    }
}
