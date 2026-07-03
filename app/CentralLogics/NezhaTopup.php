<?php

namespace App\CentralLogics;

use App\Models\NezhaTopupRequest;
use Illuminate\Support\Facades\DB;

/**
 * 哪吒 自助充值申请 (A3) — 门控/收款设置 单一真相源.
 * 供 Vendor\NezhaTopupController(端点) 与 对账中心 blade(视图) 共用, 不重复门控逻辑.
 * 全 dormant: nezha_topup_status 总闸默认0 服务端强制. 收款设置(§8①)存后台不写死.
 */
class NezhaTopup
{
    public const ACCOUNTS = ['deposit', 'guarantee', 'ad'];

    public static function setting(string $key, $default = null)
    {
        $v = DB::table('business_settings')->where('key', $key)->value('value');
        return ($v === null || $v === '') ? $default : $v;
    }

    /** 某账户腿自助充值是否开放(总闸 && 该腿闸). deposit 只受总闸; ad/guarantee 各有独立闸. */
    public static function accountOpen(string $account): bool
    {
        if (!in_array($account, self::ACCOUNTS, true)) {
            return false;
        }
        if ((int) self::setting('nezha_topup_status', 0) !== 1) {
            return false;
        }
        if ($account === 'ad') {
            return (int) self::setting('nezha_topup_ad_status', 0) === 1;
        }
        if ($account === 'guarantee') {
            return (int) self::setting('nezha_topup_guarantee_status', 0) === 1;
        }
        return true;
    }

    /** 金额上下限 AMD(后台可调). */
    public static function bounds(): array
    {
        return [
            (float) self::setting('nezha_topup_min_amd', 5000),
            (float) self::setting('nezha_topup_max_amd', 2000000),
        ];
    }

    /** 收款信息(§8①·后台可换不写死). qr 为 public 磁盘相对路径. */
    public static function payInfo(): array
    {
        return [
            'account' => self::setting('nezha_topup_alipay_account', ''),
            'name'    => self::setting('nezha_topup_alipay_name', '哪吒平台'),
            'holder'  => self::setting('nezha_topup_alipay_holder', ''),
            'qr'      => self::setting('nezha_topup_alipay_qr', ''),
        ];
    }

    /** 该商家该账户最近一条充值申请(状态卡用). */
    public static function latestRequest(int $vendorId, string $account): ?NezhaTopupRequest
    {
        return NezhaTopupRequest::where('vendor_id', $vendorId)
            ->where('account_type', $account)
            ->where('direction', 'topup')
            ->orderByDesc('id')
            ->first();
    }
}