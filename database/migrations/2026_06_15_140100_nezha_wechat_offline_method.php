<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒外卖: 微信支付独立成离线支付方式.
 * 此前只有一个共用方式「人民币支付（微信/支付宝）」(显示共用人民币码 rmb_qr_image).
 * 现拆分:
 *   - 该共用方式重命名为「支付宝」(继续显示 rmb_qr_image, 顾客用支付宝扫).
 *   - 新增「微信」方式(显示独立的 wechat_qr_image, 见 add_wechat_qr_to_restaurants 迁移).
 * 前端 OfflinePaymentForm 按方式名(微信/wechat 且非支付宝)选择显示哪张码; method_fields 沿用「付款截图必填」.
 * L1-1: 仍是顾客直付商家本人收款码, 平台不碰钱, 未引入任何代收/归集.
 * 可逆: down() 改回原名并删除「微信」方式.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ① 共用人民币方式重命名为「支付宝」(幂等: 仅当旧名存在时改)
        DB::table('offline_payment_methods')
            ->where('method_name', '人民币支付（微信/支付宝）')
            ->update(['method_name' => '支付宝', 'updated_at' => now()]);

        // ② 新增「微信」方式 (幂等: 不存在才插)
        $exists = DB::table('offline_payment_methods')
            ->where('method_name', '微信')
            ->exists();
        if (!$exists) {
            DB::table('offline_payment_methods')->insert([
                'method_name' => '微信',
                'method_fields' => json_encode([
                    [
                        'input_field_name' => '付款截图',
                        'input_type' => 'file',
                        'placeholder' => '请上传付款成功截图',
                        'is_required' => 1,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'method_informations' => json_encode([]),
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('offline_payment_methods')->where('method_name', '微信')->delete();
        DB::table('offline_payment_methods')
            ->where('method_name', '支付宝')
            ->update(['method_name' => '人民币支付（微信/支付宝）', 'updated_at' => now()]);
    }
};
