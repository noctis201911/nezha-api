<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';


    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            Route::prefix('admin')
                ->middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/admin.php'));

            Route::prefix('restaurant-panel')
                ->middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/vendor.php'));

            Route::prefix('api/v1')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api/v1/api.php'));

            Route::prefix('api/v2')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api/v2/api.php'));

        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(240)->by(optional($request->user())->id ?: $request->ip());
        });

        // 哪吒[防脚本注册 2026-07-01]: 注册双层限流(5/分钟突发+30/小时持续), 命名限流器两层key自动区分, 避免叠加未命名throttle互撞计数; reCAPTCHA落地前interim, CGNAT共享IP需复评
        RateLimiter::for('signup', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(30)->by($request->ip()),
            ];
        });

        // 哪吒[防滥用 2026-07-01]: 「没有收到餐」申诉端点限流。游客可发(guest_id可再生), 按 user_id 或 IP 键 + 硬性每IP日上限, 防批量诬告刷申诉。
        RateLimiter::for('nezha_appeal', function (Request $request) {
            $key = optional($request->user())->id ? ('u' . $request->user()->id) : ('ip' . $request->ip());
            return [
                Limit::perMinute(3)->by($key),
                Limit::perHour(10)->by($key),
                Limit::perDay(20)->by('appeal_ip_' . $request->ip()),
            ];
        });

        // 哪吒[本地生活批2 2026-07-08]: 攻略「有用」+1 端点限流。无登录墙(0级软计数), 按 IP 键防脚本刷;
        // 命名限流器独立 key, 不与其它 throttle 互撞计数(未命名共用 key 坑)。
        RateLimiter::for('nezha_guides_helpful', function (Request $request) {
            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perDay(200)->by('guides_helpful_ip_' . $request->ip()),
            ];
        });
    }
}
