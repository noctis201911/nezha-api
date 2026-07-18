<?php

namespace App\Console\Commands;

use App\CentralLogics\NezhaPaymentAddressChangeService;
use App\CentralLogics\NezhaUsdtAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InitializePaymentAddressStates extends Command
{
    protected $signature = 'nezha:payment-address-state-init
        {--apply : Persist valid missing network-state rows}
        {--restaurant= : Limit to one restaurant id}
        {--network= : Limit to TRC20 or BEP20}';

    protected $description = 'Dry-run or initialize dormant USDT network state rows without changing addresses';

    public function handle(): int
    {
        if (! Schema::hasTable('nezha_payment_network_states')) {
            $this->error('Migration has not created nezha_payment_network_states.');
            return self::FAILURE;
        }
        if ($this->option('apply') && NezhaPaymentAddressChangeService::enabled()) {
            $this->error('Refusing initialization while address-change switch is enabled.');
            return self::FAILURE;
        }

        $requestedNetwork = $this->option('network');
        $network = $requestedNetwork ? NezhaUsdtAddress::normalizeNetwork($requestedNetwork) : null;
        if ($requestedNetwork && ! $network) {
            $this->error('Unsupported network. Use TRC20 or BEP20.');
            return self::INVALID;
        }
        $networks = $network ? [$network] : [NezhaUsdtAddress::TRC20, NezhaUsdtAddress::BEP20];
        $query = DB::table('restaurants')->select('id', 'usdt_address', 'usdt_bep20_address')->orderBy('id');
        if ($this->option('restaurant')) {
            $query->where('id', (int) $this->option('restaurant'));
        }

        $summary = ['valid' => 0, 'invalid_or_empty' => 0, 'initialized' => 0, 'already_present' => 0];
        foreach ($query->cursor() as $restaurant) {
            foreach ($networks as $item) {
                $column = NezhaUsdtAddress::columnForNetwork($item);
                if (! NezhaUsdtAddress::isValid((string) $restaurant->{$column}, $item)) {
                    $summary['invalid_or_empty']++;
                    continue;
                }
                $summary['valid']++;
                $exists = DB::table('nezha_payment_network_states')
                    ->where('restaurant_id', $restaurant->id)
                    ->where('network', $item)
                    ->exists();
                if ($exists) {
                    $summary['already_present']++;
                    continue;
                }
                if ($this->option('apply')) {
                    NezhaPaymentAddressChangeService::initializeNetworkState((int) $restaurant->id, $item);
                    $summary['initialized']++;
                }
            }
        }

        $summary['mode'] = $this->option('apply') ? 'apply' : 'dry-run';
        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
