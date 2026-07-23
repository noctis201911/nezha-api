<?php

namespace App\Providers;

use Exception;
use App\Traits\AddonHelper;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Traits\ActivationClass;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

// ini_set('memory_limit', '512M');
ini_set("memory_limit",-1);
class AppServiceProvider extends ServiceProvider
{
    use ActivationClass,AddonHelper;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {


    }

    /**
     * Bootstrap any application services.
     *
     */
    public function boot(Request $request)
    {
        $legacyTokenTtlDays = config(
            'nezha_customer_browser_auth.legacy_access_token_ttl_days'
        );
        if (is_numeric($legacyTokenTtlDays)) {
            // Only newly issued customer personal tokens use this TTL.
            // Existing JWT exp claims remain unchanged across rollout and
            // rollback. An unset value preserves Passport's P1Y default.
            Passport::personalAccessTokensExpireIn(
                now()->addDays((int) $legacyTokenTtlDays)
            );
        }

        if(env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }
        Request::macro('isAny', function (array $patterns) {
            return collect($patterns)->contains(fn ($pattern) => Request::is($pattern));
        });

        // 哪吒(2026-06-18): 发件用 noreply@nezha.am(只发不收), 客户回复统一进运营 Gmail
        Mail::alwaysReplyTo('support@nezha.am');


        if (!App::runningInConsole()) {
            Config::set('addon_admin_routes',$this->get_addon_admin_routes());
            Config::set('get_payment_publish_status',$this->get_payment_publish_status());
            Config::set('default_pagination', 25);
            Paginator::useBootstrap();
            try {
                foreach(Helpers::get_view_keys() as $key=>$value)
                {
                    view()->share($key, $value);
                }
            } catch (\Exception $e){

            }
        }
    }
}
