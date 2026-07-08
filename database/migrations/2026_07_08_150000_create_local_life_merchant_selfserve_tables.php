<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活「商户轻管理面」自助自维护 —— 三张地基表（additive · 幂等 · 可回滚）。
 * 业主 0708 拍板：C 轻账号(邮箱+密码) + 全复审 + §3 完整 v1 + 邮箱自助设密/找回。
 *
 * 1) local_life_merchant_accounts        —— 商户「是我」凭证（一账号一店 v1，表结构预留将来多用户）
 * 2) local_life_merchant_changes         —— 商户提交的「待审变更」快照（全复审：过审前不触碰线上）
 * 3) local_life_merchant_password_resets —— 邮箱自助设密/找回的 Laravel 密码 broker 令牌表（独立，不与主站 password_resets 混）
 *
 * 合规：商户内容非 PII 敏感区（联系方式本就是公开展示物），不涉 L1-7 加密义务；
 *       快照留提交者指纹/IP 供审计，驳回不删快照（留证）。
 */
return new class extends Migration
{
    public function up()
    {
        // 1) 账号凭证
        if (!Schema::hasTable('local_life_merchant_accounts')) {
            Schema::create('local_life_merchant_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_id')->unique();   // 一账号一店（v1）
                $table->string('email', 191)->unique();                // 登录身份
                $table->string('password')->nullable();                // 设密前为空（邮箱自助设密）
                $table->string('contact_name', 120)->nullable();       // 联系人姓名（可选）
                $table->boolean('status')->default(true);              // 1=启用 0=停用（admin 可吊销）
                $table->timestamp('last_login_at')->nullable();
                $table->string('remember_token', 100)->nullable();
                $table->timestamps();
                $table->foreign('merchant_id')->references('id')->on('local_life_merchants')->onDelete('cascade');
            });
        }

        // 2) 待审变更快照
        if (!Schema::hasTable('local_life_merchant_changes')) {
            Schema::create('local_life_merchant_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_id')->index();
                $table->unsignedBigInteger('account_id')->nullable();  // 提交者账号
                $table->json('payload');                               // 提议的自改字段（已解析/规范化）
                $table->json('base_snapshot')->nullable();             // 提交时的线上值（算 diff / 防并发覆盖）
                $table->tinyInteger('status')->default(0)->index();    // 0=待审 1=已通过 2=已驳回
                $table->string('review_note', 255)->nullable();        // 驳回理由
                $table->unsignedBigInteger('reviewed_by')->nullable(); // 裁决超管 id
                $table->timestamp('reviewed_at')->nullable();
                $table->string('submit_ip', 45)->nullable();           // 审计
                $table->string('submit_ua', 255)->nullable();          // 审计（UA 指纹）
                $table->timestamps();
                $table->foreign('merchant_id')->references('id')->on('local_life_merchants')->onDelete('cascade');
            });
        }

        // 3) 密码 broker 令牌表（邮箱自助设密 + 找回共用）
        if (!Schema::hasTable('local_life_merchant_password_resets')) {
            Schema::create('local_life_merchant_password_resets', function (Blueprint $table) {
                $table->string('email', 191)->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('local_life_merchant_password_resets');
        Schema::dropIfExists('local_life_merchant_changes');
        Schema::dropIfExists('local_life_merchant_accounts');
    }
};
