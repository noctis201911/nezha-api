<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 哪吒 - 配送链接(Yandex 追踪链接)留存期清除。
 *
 * 合规背景(L1-7 相邻, 数据最小化/PDPA-GDPR): 商家回传的 Yandex 配送追踪链接, 在订单
 * 配送中时用于让顾客实时看骑手位置——它本质是"顾客位置的实时链接"。订单到终态(已送达/
 * 已取消/已退款/失败)后:① Yandex 那侧的实时追踪页会自己失效, 链接已无实时数据;
 * ② 平台只需在一段纠纷窗口内保留它供核查"商家是否叫车"。窗口过后即应清除以最小化 PII 负债。
 *
 * 做法: 只把终态、且超过保留期的订单的 yandex_tracking_url + delivery_link_reminded_at
 * 两个字段置空; 【保留订单行本身与全部财务/状态字段】(订单是财务记录, L1-4/合规另行留存)。
 *
 * 保留天数: business_settings.nezha_yandex_link_retention_days (默认 30)。
 * 开关:     business_settings.nezha_yandex_link_purge_status     (默认 1=开; 设 0 停用)。
 */
class PurgeYandexLinks extends Command
{
    protected $signature = 'nezha:purge-yandex-links {--dry-run : 只报告将清除什么, 不实际清除}';

    protected $description = '哪吒: 按保留期清除终态订单的 Yandex 配送追踪链接(数据最小化); 仅置空链接字段, 保留订单行与财务/状态。';

    public function handle()
    {
        $enabled = (int) (DB::table('business_settings')
            ->where('key', 'nezha_yandex_link_purge_status')->value('value') ?? 1);
        if ($enabled !== 1) {
            $this->info('哪吒配送链接清除: 开关关闭(nezha_yandex_link_purge_status=0), 跳过。');
            return self::SUCCESS;
        }

        $days = (int) (DB::table('business_settings')
            ->where('key', 'nezha_yandex_link_retention_days')->value('value') ?? 30);
        if ($days < 1) {
            $days = 30;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dry = (bool) $this->option('dry-run');
        $terminal = ['delivered', 'canceled', 'refunded', 'failed'];

        $this->info('哪吒配送链接清除: 保留期=' . $days . '天, 截止=' . $cutoff->toDateTimeString()
            . ', 模式=' . ($dry ? 'DRY-RUN(只报告)' : '实清'));

        $q = DB::table('orders')
            ->whereIn('order_status', $terminal)
            ->whereNotNull('yandex_tracking_url')
            ->where('yandex_tracking_url', '!=', '')
            ->where('updated_at', '<', $cutoff);

        $count = (clone $q)->count();
        $this->info('命中待清除订单数: ' . $count);

        if ($dry) {
            foreach ((clone $q)->limit(20)->get(['id', 'order_status', 'updated_at']) as $row) {
                $this->line('  [DRY] 将清除 order#' . $row->id . ' (' . $row->order_status . ', 终态于 ' . $row->updated_at . ')');
            }
            $this->info('[DRY-RUN] 将清除 ' . $count . ' 单的配送链接。订单行/财务/状态不动。');
            return self::SUCCESS;
        }

        $purged = (clone $q)->update([
            'yandex_tracking_url'        => null,
            'delivery_link_reminded_at'  => null,
            'updated_at'                 => now(),
        ]);

        $msg = '已清除 ' . $purged . ' 单的 Yandex 配送链接(保留期 ' . $days . '天)。订单行/财务/状态未触碰。';
        $this->info($msg);
        Log::info('NEZHA_PURGE_YANDEX_LINKS: ' . $msg);

        return self::SUCCESS;
    }
}
