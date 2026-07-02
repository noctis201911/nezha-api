<?php

namespace App\Providers;

use App\Models\BusinessSetting;
use App\Models\DataSetting;
use App\Observers\BusinessSettingObserver;
use App\Models\Order;
use App\Observers\DataSettingObserver;
use App\Observers\NezhaOrderCountObserver;
use App\Observers\NezhaOfflinePaymentCountObserver;
use App\Models\OfflinePayments;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        BusinessSetting::observe(BusinessSettingObserver::class);
        DataSetting::observe(DataSettingObserver::class);
        Order::observe(NezhaOrderCountObserver::class); // 哪吒P1b-A: 订单写入失效计数缓存
        OfflinePayments::observe(NezhaOfflinePaymentCountObserver::class); // 哪吒P1b-A: 凭证写入失效计数缓存(待确认收款增量)
    }
}
