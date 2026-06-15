<?php

namespace App\Console;

use App\Models\BusinessSetting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 哪吒: 每日清除超过保留期的顾客离线支付凭证PII(默认90天, 后台 nezha_payment_proof_retention_days 可调)。
        // 只抹 PII 字段+关联截图, 保留行/状态供审计; 不动订单/交易/链上记录。
        $schedule->command('nezha:purge-payment-proofs')->dailyAt('03:30')->withoutOverlapping();

        // 哪吒: 每日清除已过期的本地生活UGC帖PII(contact_info + 上传图片), 保留帖子行/状态供审计。
        $schedule->command('nezha:purge-locallife-pii')->dailyAt('03:40')->withoutOverlapping();

        // 哪吒: 每日清除已结案(已完成/无效)且超过保留期的商家入驻线索PII(默认90天自结案起算, 后台 merchant_leads_retention_days 可调)。
        // 只抹 PII(联系人/电话/微信/地址/备注), 保留行/店名/品类/状态供审计; 进行中(待跟进/跟进中)的线索不动。
        $schedule->command('nezha:purge-merchant-leads')->dailyAt('03:50')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
