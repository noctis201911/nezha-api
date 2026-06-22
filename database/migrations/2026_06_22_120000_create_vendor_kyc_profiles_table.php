<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 哪吒 商家 KYC 资料（轻量·方案B）— 只存「核验结论」，默认不存证件扫描件。
 *
 * 背景: 现有建商家流程不落任何法人真名/证件/收款主体字段 ——
 *   ① L1-6 制裁名单「命中即拒」在入驻环节空跑(连法人名都没存,无从筛);
 *   ② 保证金追回/出事找人无据; ③ 银行/律师必问「怎么核实商家身份」。
 * 本表补这个洞: 一店一行,记录运营当面/视频核验后的结论。
 *
 * 🔴 L1-7 PII: legal_name / id_doc_number / bank_account / contact_phone 等为 PII,
 *   ① 模型层走 Eloquent 'encrypted' cast(应用层再加一层,防表空间 key 泄露;这些字段不参与
 *      SQL 搜索 —— 制裁筛查在写入时内存比对,不走 WHERE,故加密不碍事);
 *   ② MySQL 5.7 新表【不继承】全库表空间加密,必须显式 ENCRYPTION='Y'(对齐 local_life_reports)。
 *   留存定性: 这些是 AML/CDD 核验记录,按反洗钱惯例留存 >=5 年(具体年限待律师确认),
 *   不进 30/90 天 PII 清除任务 —— 故本迁移不配 purge 命令。
 *
 * screen_* 字段为阶段1(制裁名字筛查)预留,基础模块默认 not_run。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_kyc_profiles')) {
            return;
        }

        Schema::create('vendor_kyc_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_id')->unique();   // 一店一行

            // —— 核验结论(PII, 模型层 encrypted cast) ——
            $table->text('legal_name')->nullable();              // 法人/经营者真实姓名(拉丁拼写, 制裁筛查对象)
            $table->text('legal_name_local')->nullable();        // 本地文字姓名(亚/中/俄), 可空
            $table->text('beneficial_owner_name')->nullable();   // 受益所有人(与法人相同时可空)
            $table->string('id_doc_type', 32)->nullable();       // passport/national_id/residence_permit/business_license/other
            $table->text('id_doc_number')->nullable();           // 证件号
            $table->text('bank_account')->nullable();            // 收款账户(户名+账号 / 支付宝实名 / USDT 收款地址)
            $table->text('contact_phone')->nullable();           // 联系电话
            $table->text('note')->nullable();                    // 运营内部备注

            // —— 核验/审核状态(非 PII) ——
            $table->string('verify_method', 16)->nullable();     // in_person/video/document
            $table->string('kyc_status', 16)->default('none')->index(); // none/pending/approved/rejected
            $table->string('reviewer', 191)->nullable();         // 审核人(admin 邮箱/名)
            $table->dateTime('reviewed_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->dateTime('closed_at')->nullable();           // 拒绝/商家注销时间(留存倒计时锚点)

            // —— 制裁筛查结果(阶段1 填充, 基础模块预留) ——
            $table->string('screen_status', 16)->default('not_run')->index(); // not_run/clear/possible/hit
            $table->text('screen_detail')->nullable();
            $table->dateTime('screened_at')->nullable();

            $table->timestamps();
        });

        // 显式表空间加密(L1-7): 5.7 新表不继承全库加密,必须手动开。keyring 未就绪时不阻断建表。
        try {
            DB::statement("ALTER TABLE `vendor_kyc_profiles` ENCRYPTION='Y'");
        } catch (\Throwable $e) {
            // 静默: keyring 异常不应阻断迁移; 加密态在收尾脚本/QA 复核
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_kyc_profiles');
    }
};
