<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活「生活攻略」(批2 · PGC)。运营后台录入，前端攻略列表/详情以本表为准。
 * 合规 L1-1：纯信息展示，不碰交易/收款；内嵌店卡只跳转不带促销承诺。
 */
class Guide extends Model
{
    protected $table = 'nezha_guides';

    protected $fillable = [
        'title', 'slug', 'cover_url', 'summary', 'body_md',
        'info_as_of', 'is_sensitive_topic', 'helpful_count',
        'status', 'sort', 'published_at',
    ];

    protected $casts = [
        'is_sensitive_topic' => 'boolean',
        'helpful_count'      => 'integer',
        'status'             => 'integer',
        'sort'               => 'integer',
        'published_at'       => 'datetime',
    ];

    /** 时效是否过期（info_as_of 距今 > 180 天）→ 详情页琥珀提醒条 / admin stale 徽标 */
    public function isStale(): bool
    {
        $d = $this->infoAsOfDate();
        return $d ? $d->lt(Carbon::now()->subDays(180)) : false;
    }

    /**
     * info_as_of（"YYYY-MM" 字符）→ 该月首日 Carbon；解析失败返回 null。
     * 时效判断口径：以「信息截至月」的月初为准（比月末更保守，早一点亮提醒不误伤）。
     */
    public function infoAsOfDate(): ?Carbon
    {
        $s = trim((string) $this->info_as_of);
        if (!preg_match('/^(\d{4})-(\d{1,2})$/', $s, $m)) {
            return null;
        }
        try {
            return Carbon::create((int) $m[1], (int) $m[2], 1, 0, 0, 0);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
