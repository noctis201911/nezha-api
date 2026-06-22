<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 哪吒制裁筛查③ — OFAC SDN 人名/实体名 + 别名(公开名单, 非 PII 不加密).
 * 由 nezha:sync-sanction-list 解析 SDN XML 的 firstName/lastName/akaList 刷新;
 * NezhaKycScreen::screen_name() 据 name_norm 比对法人/受益人姓名。
 * name_type: individual(个人) / entity(实体) / other.
 */
class NezhaSanctionName extends Model
{
    protected $table = 'nezha_sanction_names';
    protected $guarded = ['id'];
    protected $casts = [
        'added_at'       => 'datetime',
        'last_seen_sync' => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];
}
