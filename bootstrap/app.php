<?php

use App\Http\Middleware\ActivationCheckMiddleware;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\APIGuestMiddleware;
use App\Http\Middleware\Authenticate;
// Core Laravel web middleware
use App\Http\Middleware\DmTokenIsValid;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\InstallationMiddleware;
use App\Http\Middleware\Localization;
use App\Http\Middleware\LocalizationMiddleware;
use App\Http\Middleware\MaintenanceMode;
// Custom middleware
use App\Http\Middleware\ModulePermissionMiddleware;
use App\Http\Middleware\RateLimiterMiddleware;
use App\Http\Middleware\ReactValid;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\Subscription;
use App\Http\Middleware\VendorMiddleware;
use App\Http\Middleware\VendorTokenIsValid;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))

    ->withRouting(
        // commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )

    ->withMiddleware(function (Middleware $middleware) {

        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->trustProxies(at: '*');

        $middleware->group('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            LocalizationMiddleware::class,
            Localization::class,
        ]);

        $middleware->group('api', [
            \App\Http\Middleware\QueryCountGuard::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'auth' => Authenticate::class,
            'guest' => RedirectIfAuthenticated::class,

            'admin' => AdminMiddleware::class,
            'vendor' => VendorMiddleware::class,
            'vendor.api' => VendorTokenIsValid::class,
            'dm.api' => DmTokenIsValid::class,
            'module' => ModulePermissionMiddleware::class,
            'installation-check' => InstallationMiddleware::class,
            'actch' => ActivationCheckMiddleware::class,
            'localization' => LocalizationMiddleware::class,
            'subscription' => Subscription::class,
            'react' => ReactValid::class,
            'apiGuestCheck' => APIGuestMiddleware::class,
            'maintenance' => MaintenanceMode::class,
            'rateLimiter' => RateLimiterMiddleware::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions) {
        //
    })

    // 哪吒: 任务调度在 Laravel 12 走这里(bootstrap/app.php)，不是旧版 app/Console/Kernel.php::schedule()
    // —— 升级到 L12 后旧 Kernel 的 schedule() 已不被框架调用(commands: 未启用/无 withSchedule)。
    // 命令本身仍由 L11+ 自动发现 app/Console/Commands 注册。
    ->withSchedule(function (Schedule $schedule) {
        // 每天 04:10 从公开汇率源自动对齐结算汇率(顾客↔商家直付的事实结算汇率)。
        // 用户已批准自动化(L2, 贴市场中间价不加缓冲)；带护栏，状态写入 business_settings.nezha_fx_last_sync。
        $schedule->command('nezha:sync-fx-rate')->dailyAt('04:10')->withoutOverlapping();

        // 🔴 L1-7 PII 到期删: 这 3 个每日清除任务原只定义在已失效的 app/Console/Kernel.php 里, 自 L12 升级后
        //    一直未运行。2026-06-16 经用户批准 + 三任务 --dry-run 实测命中 0(无超期积压, 接回零删除最安全的时机)后
        //    迁来此处正式接回每日调度。只抹超期 PII 字段+关联文件, 保留行/状态供审计; 不动订单/交易/链上记录。
        $schedule->command('nezha:purge-payment-proofs')->dailyAt('03:30')->withoutOverlapping();
        $schedule->command('nezha:purge-locallife-pii')->dailyAt('03:40')->withoutOverlapping();
        $schedule->command('nezha:purge-merchant-leads')->dailyAt('03:50')->withoutOverlapping();

        // 每天 09:00 检查商家预存佣金, 低于商家自设阈值(或为负)发提醒邮件(商家可在商家后台自助开关/设阈值/设邮箱)。
        $schedule->command('nezha:check-deposit-alerts')->dailyAt('09:00')->withoutOverlapping();

        // 🔴 L1-6 制裁名单筛查: 每天 04:30 从 OFAC 公开 SDN 名单拉「数字货币地址」入本地表(nezha_sanction_addresses),
        //    供 USDT 付款来源地址实时比对。带护栏: 取数/解析失败则保留旧名单不动(失败安全), 状态写 nezha_sanction_last_sync。
        $schedule->command('nezha:sync-sanction-list')->dailyAt('04:30')->withoutOverlapping();

        // 哪吒 B方案 收尾兜底(C): handover 超过 N 小时(默认24h, business_settings.nezha_auto_finalize_handover_hours)
        //   无人「确认收货」的配送/自取单, 自动判为已送达并结算佣金(恰好一次)。开关 nezha_auto_finalize_handover_status(默认1开)。
        //   背景: 平台不配送、无骑手点送达、商家不知Yandex何时送达 → 顾客忘点会永远卡 handover, 佣金永不入账。每小时跑一次。
        $schedule->command('nezha:auto-finalize-handover')->hourly()->withoutOverlapping();
    })

    ->create();

// $requestUri = $_SERVER['REQUEST_URI'] ?? '';
// if (!str_starts_with($requestUri, '/image-proxy')) {
//     header('Access-Control-Allow-Origin: *');
// }
// header('Access-Control-Allow-Methods: *');
// header('Access-Control-Allow-Headers: *');
