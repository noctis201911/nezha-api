<?php

namespace App\Console\Commands;

use App\Models\BusinessSetting;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

class RestaurantDisbursementScheduler extends Command
{
    protected $signature = 'restaurant:disbursement';
    protected $description = 'Restaurant disbursement scheduling based on business settings';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        // [哪吒 B方案/组3 拔二清腿] 已停用: 平台永不打款给商家。命令保留但不再触发打款(双保险, generate_disbursement 本身也已禁用)。
        $this->info('Restaurant disbursement scheduler is DISABLED (Nezha B-plan). No-op.');
        return 0;

        app('App\Http\Controllers\Admin\RestaurantDisbursementController')->generate_disbursement();
        $this->info('Restaurant disbursement scheduler executed successfully.');
    }
}
