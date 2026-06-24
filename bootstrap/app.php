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
            'vmodule' => \App\Http\Middleware\VendorApiModulePermission::class,
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
        // 哪吒[L1-7相邻 数据最小化]: 终态订单的 Yandex 配送链接超保留期清除(默认30天, 开关 nezha_yandex_link_purge_status)
        $schedule->command('nezha:purge-yandex-links')->dailyAt('03:55')->withoutOverlapping();

        // 每天 09:00 检查商家预存佣金, 低于商家自设阈值(或为负)发提醒邮件(商家可在商家后台自助开关/设阈值/设邮箱)。
        $schedule->command('nezha:check-deposit-alerts')->dailyAt('09:00')->withoutOverlapping();

        // 🔴 L1-6 制裁名单筛查: 每天 04:30 从 OFAC 公开 SDN 名单拉「数字货币地址」入本地表(nezha_sanction_addresses),
        //    供 USDT 付款来源地址实时比对。带护栏: 取数/解析失败则保留旧名单不动(失败安全), 状态写 nezha_sanction_last_sync。
        $schedule->command('nezha:sync-sanction-list')->dailyAt('04:30')->withoutOverlapping();

        // 哪吒 B方案 收尾兜底(C): handover 超过 N 小时(默认24h, business_settings.nezha_auto_finalize_handover_hours)
        //   无人「确认收货」的配送/自取单, 自动判为已送达并结算佣金(恰好一次)。开关 nezha_auto_finalize_handover_status(默认1开)。
        //   背景: 平台不配送、无骑手点送达、商家不知Yandex何时送达 → 顾客忘点会永远卡 handover, 佣金永不入账。每小时跑一次。
        $schedule->command('nezha:auto-finalize-handover')->hourly()->withoutOverlapping();

        // 哪吒 B方案 订单超时兜底: 杜绝订单无限停留在待接单/备餐中。规则见 docs/ORDER_TIMEOUT_RULES.md。
        //   每分钟扫描, 据阈值执行幂等动作(提醒商家/自动取消未付款单/已付款单待退款留痕+通知商家退款/备餐超时升级客服)。
        //   总开关 business_settings.nezha_timeout_status(默认1开)。
        $schedule->command('nezha:order-timeout-sweep')->everyMinute()->withoutOverlapping();

        // 哪吒广告计费 T3: 到投放起始日对「已通过+未扣费」广告从商家保证金扣全额(单价×天数)。
        //   L2 资金, 流水类型 advertisement_fee; 总开关 nezha_ad_billing_status(默认0关, 关时命令直接返回零扣费)。
        //   幂等(paid_at IS NULL 防重闸+事务内 lockForUpdate), 每小时跑一次, 起始日到达后尽快扣费; 余额不足跳过并提醒充值。
        $schedule->command('nezha:charge-ad-on-start')->hourly()->withoutOverlapping();

        // 哪吒 B方案 商家逾期未退款兜底: 对 pending_merchant_refund 超过阈值天数仍未退的留痕施加非资金约束
        //   (记风控 refund_overdue / 催办商家 / 告警运营; 停接单由运营后台手动)。
        //   总开关 nezha_refund_overdue_status(默认0关), 关时命令直接返回。每天 09:30 跑一次。
        $schedule->command('nezha:refund-overdue-sweep')->hourly()->withoutOverlapping();

        // 哪吒 反馈日报(方案A): 每天 06:00 把昨日顾客反馈(评价/退款/客服)聚合 → AI 摘要 → 存库 + 发超管 Telegram。
        //   总开关 nezha_feedback_digest_status(默认0关), 关时命令直接返回; AI 复用 nezha_cs_ai_*, 未开则降级仅统计。
        $schedule->command('nezha:feedback-digest')->dailyAt('06:00')->withoutOverlapping();

        // 哪吒 使用埋点维护(方案C): 每日回填加购转化 + 按保留期清理(加购30天/搜索词180天)。
        $schedule->command('nezha:purge-analytics')->dailyAt('03:25')->withoutOverlapping();
    })

    ->create();

// $requestUri = $_SERVER['REQUEST_URI'] ?? '';
// if (!str_starts_with($requestUri, '/image-proxy')) {
//     header('Access-Control-Allow-Origin: *');
// }
// header('Access-Control-Allow-Methods: *');
// header('Access-Control-Allow-Headers: *');
