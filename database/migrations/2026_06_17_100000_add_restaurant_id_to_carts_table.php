<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('carts', 'restaurant_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->unsignedBigInteger('restaurant_id')->nullable()->after('item_id')->index();
            });
        }

        // Backfill existing rows from their item (Food / ItemCampaign) via Eloquent
        // to avoid namespaced-class backslash escaping in raw SQL. Cart volume is tiny.
        foreach (\App\Models\Cart::whereNull('restaurant_id')->get() as $c) {
            $cls = $c->item_type;
            if ($cls && class_exists($cls)) {
                $it = $cls::find($c->item_id);
                if ($it && isset($it->restaurant_id)) {
                    $c->restaurant_id = $it->restaurant_id;
                    $c->saveQuietly();
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('carts', 'restaurant_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->dropColumn('restaurant_id');
            });
        }
    }
};
