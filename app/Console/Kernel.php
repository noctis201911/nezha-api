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
