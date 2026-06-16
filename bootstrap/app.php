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
        // 每周一 04:10 从公开汇率源自动对齐结算汇率(顾客↔商家直付的事实结算汇率)。
        // 用户已批准自动化(L2, 贴市场中间价不加缓冲)；带护栏，状态写入 business_settings.nezha_fx_last_sync。
        $schedule->command('nezha:sync-fx-rate')->weeklyOn(1, '04:10')->withoutOverlapping();

        // ⚠️ 注意: app/Console/Kernel.php 里那 3 个每日 PII 清除任务(purge-payment-proofs / purge-locallife-pii
        //    / purge-merchant-leads, L1-7 合规)自 L12 升级后未在运行。重新挂入前需用户确认(首次运行会一次性
        //    不可逆删除超期 PII 积压)，建议先 --dry-run。暂不在此挂入。
    })

    ->create();

// $requestUri = $_SERVER['REQUEST_URI'] ?? '';
// if (!str_starts_with($requestUri, '/image-proxy')) {
//     header('Access-Control-Allow-Origin: *');
// }
// header('Access-Control-Allow-Methods: *');
// header('Access-Control-Allow-Headers: *');
