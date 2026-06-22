<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒 商家 KYC 资料（轻量·方案B, 只存核验结论, 默认不存扫描件）。
 *
 * 一店一行(restaurant_id 唯一)。PII 字段走 'encrypted' cast(应用层加密,
 * 与表空间加密叠加;这些字段不做 SQL WHERE 搜索 —— 制裁筛查在内存比对)。
 *
 * 留存: AML/CDD 核验记录, 按反洗钱惯例留存 >=5 年(待律师定具体年限),
 * 不进 PII 到期清除任务。表已 ENCRYPTION='Y'(见迁移)。
 *
 * kyc_status:  none(未建档) / pending(待审核) / approved(通过) / rejected(拒绝)
 * screen_status: not_run / clear / possible(疑似,转人工) / hit(精确命中,拒)
 */
class VendorKycProfile extends Model
{
    protected $table = 'vendor_kyc_profiles';

    protected $guarded = ['id'];

    protected $casts = [
        // —— PII: 应用层加密 ——
        'legal_name'            => 'encrypted',
        'legal_name_local'      => 'encrypted',
        'beneficial_owner_name' => 'encrypted',
        'id_doc_number'         => 'encrypted',
        'bank_account'          => 'encrypted',
        'contact_phone'         => 'encrypted',
        'note'                  => 'encrypted',
        // —— 时间 ——
        'reviewed_at' => 'datetime',
        'closed_at'   => 'datetime',
        'screened_at' => 'datetime',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }

    /** 证件类型中文标签(展示用)。 */
    public static function docTypeLabel(?string $t): string
    {
        return [
            'passport'          => '护照',
            'national_id'       => '身份证',
            'residence_permit'  => '居留证',
            'business_license'  => '营业执照',
            'other'             => '其它',
        ][$t] ?? ($t ?: '—');
    }

    /** 核验方式中文标签。 */
    public static function verifyMethodLabel(?string $m): string
    {
        return [
            'in_person' => '当面核验',
            'video'     => '视频核验',
            'document'  => '仅凭文件',
        ][$m] ?? ($m ?: '—');
    }
}
