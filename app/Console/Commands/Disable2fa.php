<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;

/**
 * 哪吒 - 应急关闭某管理员的两步验证(认证器丢失/被锁在外面时用)。
 * 用法: php artisan nezha:2fa-disable admin@example.com
 */
class Disable2fa extends Command
{
    protected $signature = 'nezha:2fa-disable {email : 管理员邮箱}';

    protected $description = '哪吒应急: 关闭指定管理员的后台两步验证(认证器丢失时通过SSH执行)';

    public function handle()
    {
        $email = $this->argument('email');
        $admin = Admin::where('email', $email)->first();

        if (!$admin) {
            $this->error('找不到该管理员: ' . $email);
            return self::FAILURE;
        }

        if (!$admin->two_factor_enabled && !$admin->two_factor_secret) {
            $this->info('该管理员本就未开启两步验证, 无需操作。');
            return self::SUCCESS;
        }

        $admin->two_factor_secret = null;
        $admin->two_factor_enabled = false;
        $admin->two_factor_recovery_codes = null;
        $admin->save();

        $this->info('已关闭 ' . $email . ' 的两步验证。该管理员现在可仅用密码登录, 建议尽快重新设置。');
        return self::SUCCESS;
    }
}
