<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 本地生活商家页「笔记」内容层（批N · 新表 · 可回滚）。
 *
 * 图文笔记：商家写店内动态/作品(author_type=merchant)，客户写体验(author_type=customer)，
 * 全部人工审核后展示；无过审笔记整卡隐藏。笔记 ≠ 评价(无星级/无点赞/无好评率)。
 *
 * status(审核态): 0待审 / 1过审 / 2驳回 / 3下架。新笔记一律 0 不可见（不许先显后审）。
 * user_id: 客户作者(auth:api)；author_type=merchant 时为 null。
 *   —— 与 local_life_posts 同口径：nullable + 无 FK，用户注销(硬删)后笔记留存、作者回落「用户已注销」。
 * merchant_id: FK→local_life_merchants，商家删则笔记随删(cascade)。
 *
 * L1-1 纯信息墙：笔记全链无任何交易/预订/收款元素。
 * L1-7 PII：笔记为公开展示内容、明令禁联系方式，本无 PII；仍显式 ENCRYPTION='Y' 防未审前 body 夹带个资落明文盘。
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('local_life_merchant_notes')) {
            Schema::create('local_life_merchant_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_id');
                $table->enum('author_type', ['merchant', 'customer'])->default('customer');
                $table->unsignedBigInteger('user_id')->nullable()->index(); // 客户作者(注销后留 null 孤儿, 同 posts)
                $table->string('title', 60)->nullable();
                $table->text('body');
                $table->json('images')->nullable();                          // ["local-life-note/xxx.webp", ...]
                $table->unsignedTinyInteger('status')->default(0);           // 0待审 1过审 2驳回 3下架
                $table->string('reject_reason', 255)->nullable();
                $table->timestamps();
                $table->index(['merchant_id', 'status', 'created_at']);
                $table->foreign('merchant_id')->references('id')->on('local_life_merchants')->onDelete('cascade');
            });

            // 显式表空间加密(L1-7)：5.7 新表不继承全库加密，必须手动开（keyring 未就绪不阻断建表）
            try {
                DB::statement("ALTER TABLE `local_life_merchant_notes` ENCRYPTION='Y'");
            } catch (\Throwable $e) {
                // 静默：加密态在收尾脚本里复核
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('local_life_merchant_notes');
    }
};
