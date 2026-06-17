<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒制裁筛查② — 制裁/黑名单地址(OFAC SDN 数字货币地址本地缓存).
 * 由 nezha:sync-sanction-list 定时刷新; NezhaSanctionScreen 据此比对付款来源地址.
 * addr_kind: evm(0x.., 小写规范化) / tron(T.., 大小写敏感原样) / other.
 */
class NezhaSanctionAddress extends Model
{
    protected $table = 'nezha_sanction_addresses';
    protected $guarded = ['id'];
    protected $casts = [
        'added_at'       => 'datetime',
        'last_seen_sync' => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];
}
