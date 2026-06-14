<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMerchantLeadsTable extends Migration
{
    public function up()
    {
        Schema::create('merchant_leads', function (Blueprint $table) {
            $table->id();
            $table->string('store_name');
            $table->string('contact_name');
            $table->string('phone');            // PII - whole-DB tablespace encryption at rest, masked in demo mode
            $table->string('wechat')->nullable();
            $table->string('address')->nullable();
            $table->string('category')->nullable();
            $table->text('note')->nullable();
            $table->string('source')->nullable()->default('h5');
            // status: 0=待跟进 1=跟进中 2=已完成 3=无效
            $table->unsignedTinyInteger('status')->default(0);
            $table->boolean('seen')->default(0);
            $table->string('ip')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('merchant_leads');
    }
}
