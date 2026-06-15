<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 哪吒 - 商家入驻线索 PII 留存期清除（L1-7: PII 到期删 / PDPA-GDPR 最小留存）。
 *
 * merchant_leads 是顾客「我的→商家入驻」留下的入驻意向, 含联系人/电话/微信/地址等 PII。
 * 与举报/支付凭证不同: 线索要留到运营联系完成, 不能按登记时间一刀切删,
 * 否则会误删尚未跟进的真实潜在商家。
 *
 * 因此触发条件 = 线索"已结案"(status: 2已完成 / 3无效) + 距最后更新(updated_at, ≈结案时间)
 * 超过保留期。做法: 抹掉 contact_name/phone/wechat/address/note(置 null),
 * 保留 行/store_name/category/status/时间戳 供审计("曾有此线索, 品类X, 结果Y")。
 * status 0待跟进 / 1跟进中 的行【绝不触碰】。
 *
 * 保留期: business_settings.merchant_leads_retention_days(默认 90 天, 自结案起算)。
 * 注: 本库已全表静态加密(at-rest), 本命令是"到期主动删"这层(超出加密)的持续义务。
 */
class PurgeMerchantLeads extends Command
{
    protected $signature = 'nezha:purge-merchant-leads {--dry-run : 只报告将清除什么, 不实际删除}';

    protected $description = '哪吒: 清除已结案(已完成/无效)且超过保留期的商家入驻线索PII(联系人/电话/微信/地址/备注), 保留行/店名/品类/状态供审计; 进行中的线索不动。';

    // 已结案、可进入清理倒计时的状态: 2已完成 / 3无效（0待跟进 / 1跟进中 绝不触碰）
    private const CLOSED_STATUSES = [2, 3];

    public function handle()
    {
        $days = (int) (DB::table('business_settings')
            ->where('key', 'merchant_leads_retention_days')
            ->value('value') ?? 90);
        if ($days < 1) {
            $days = 90;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        $this->info('哪吒商家线索PII清除: 保留期=' . $days . '天(自结案起算), 截止=' . $cutoff->toDateTimeString() . ', 模式=' . ($dry ? 'DRY-RUN(只报告)' : '实删'));

        // 命中: 已结案(status 2/3) + 结案后超过保留期(updated_at < cutoff) + PII 还没清过
        $rows = DB::table('merchant_leads')
            ->whereIn('status', self::CLOSED_STATUSES)
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) {
                $q->whereNotNull('contact_name')
                    ->orWhereNotNull('phone')
                    ->orWhereNotNull('wechat')
                    ->orWhereNotNull('address')
                    ->orWhereNotNull('note');
            })
            ->get(['id', 'status', 'updated_at']);

        $this->info('命中待清除线索数: ' . $rows->count());

        $purged = 0;

        foreach ($rows as $row) {
            if ($dry) {
                $this->line('  [DRY] 将清除 merchant_leads#' . $row->id . ' PII (status=' . $row->status . ', 结案于 ' . $row->updated_at . ')');
                $purged++;
                continue;
            }

            DB::table('merchant_leads')->where('id', $row->id)->update([
                'contact_name' => null,
                'phone'        => null,
                'wechat'       => null,
                'address'      => null,
                'note'         => null,
                'updated_at'   => now(),
            ]);
            $purged++;
        }

        $msg = ($dry ? '[DRY-RUN] 将清除 ' : '已清除 ') . $purged
            . ' 条已结案商家线索的 PII(联系人/电话/微信/地址/备注), 保留 行/店名/品类/状态 供审计。进行中线索不动。';
        $this->info($msg);
        Log::info('NEZHA_PURGE_MERCHANT_LEADS_PII: ' . $msg);

        return self::SUCCESS;
    }
}
