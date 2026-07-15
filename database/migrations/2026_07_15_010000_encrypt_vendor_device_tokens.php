<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_device_tokens', function (Blueprint $table): void {
            $table->dropUnique('vdt_token_unique');
            $table->text('fcm_token')->change();
            $table->char('token_hash', 64)->nullable()->after('fcm_token');
        });

        DB::table('vendor_device_tokens')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                $plaintext = (string) $row->fcm_token;
                DB::table('vendor_device_tokens')->where('id', $row->id)->update([
                    'fcm_token' => Crypt::encryptString($plaintext),
                    'token_hash' => hash('sha256', $plaintext),
                ]);
            }
        });

        Schema::table('vendor_device_tokens', function (Blueprint $table): void {
            $table->unique('token_hash', 'vdt_token_hash_unique');
        });
    }

    public function down(): void
    {
        DB::table('vendor_device_tokens')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('vendor_device_tokens')->where('id', $row->id)->update([
                    'fcm_token' => Crypt::decryptString((string) $row->fcm_token),
                ]);
            }
        });

        Schema::table('vendor_device_tokens', function (Blueprint $table): void {
            $table->dropUnique('vdt_token_hash_unique');
            $table->dropColumn('token_hash');
            $table->string('fcm_token', 512)->change();
            $table->unique('fcm_token', 'vdt_token_unique');
        });
    }
};
