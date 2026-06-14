<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLocalLifePostsTable extends Migration
{
    public function up()
    {
        Schema::create('local_life_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            // 对应前端顶栏5大类 + 子服务分组（作为卡片左上角 tag 文字）
            // 值域: 租房合租 / 找工作 / 二手闲置 / 养车出行 / 装修维修 /
            //       找服务·教育培训 / 找服务·签证法律 / 找服务·接送拼车 /
            //       上门服务·家政保洁 / 上门服务·搬家 / 上门服务·维修水电 / 免费·赠送
            $table->string('category', 60);
            // 对应前端标签筛选栏: 推荐 / 租房 / 招聘 / 二手 / 免费 / 服务
            $table->string('tab', 20)->default('推荐');
            $table->text('description')->nullable();
            $table->string('cover_emoji', 10)->nullable();    // 卡片封面大号 emoji，如 🏠
            $table->string('cover_color', 40)->nullable();    // 封面背景色或 CSS 类，如 #F3E9DD 或 c-rent
            $table->unsignedBigInteger('price_amd')->nullable(); // 价格（亚美尼亚德拉姆）
            $table->string('price_suffix', 20)->nullable();   // /月 / /月起 / 面议 等
            $table->boolean('is_free')->default(false);       // 免费：true 时价格显示"免费"
            $table->string('area_label', 80)->nullable();     // 面积/规格，如 45㎡·中心区
            $table->string('location_label', 60)->nullable(); // 地点，如 Kentron
            $table->boolean('is_urgent')->default(false);     // 急招标签
            $table->unsignedInteger('want_count')->default(0); // 想要数（运营手动设，不是真实点击）
            // PII 字段：全库 MySQL tablespace 加密已覆盖（见 INVARIANTS.md 风控③）
            // 仅在顾客端详情接口对已登录用户返回；列表接口不返回
            $table->text('contact_info')->nullable();         // PII
            $table->dateTime('expires_at')->nullable();       // 过期时间，用于后续 PII 清除（窗口④）
            // 0=草稿（不公开）  1=已发布  2=已下线
            $table->unsignedTinyInteger('status')->default(0);
            $table->string('source', 20)->default('admin');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('local_life_posts');
    }
}
