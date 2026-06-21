<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活类目（后台可管理）。前端金刚区 / 发帖类目下拉 / 校验白名单均以本表为准。
 *
 * compliance_level 合规等级（坚决不能上线哪些业务的机制载体）：
 *   0 = 可上线（普通类目）
 *   1 = 需牌照 / 人工审（移民/签证/按摩/医疗等，可做信息墙，但每个商家逐个人工核；= 原 is_sensitive）
 *   2 = 硬禁（换汇/加密买卖/医美注射/性服务/赌博/制裁规避等，坚决不能上线，不可启用、前端不渲染）
 * is_sensitive 保留向后兼容，由控制器同步维护为 (compliance_level >= 1)。
 */
class LocalLifeCategory extends Model
{
    public const LEVEL_OK        = 0; // 可上线
    public const LEVEL_LICENSED  = 1; // 需牌照 / 人工审
    public const LEVEL_BANNED    = 2; // 硬禁

    protected $fillable = [
        'name', 'emoji', 'color', 'tab', 'kind', 'sort_order', 'is_sensitive', 'compliance_level', 'status',
    ];

    protected $casts = [
        'is_sensitive'     => 'boolean',
        'compliance_level' => 'integer',
        'status'           => 'boolean',
        'sort_order'       => 'integer',
    ];

    /** 启用中、且非硬禁的类目名数组（API 校验白名单 / 前端兜底用）。硬禁类目永不进白名单。 */
    public static function activeNames(): array
    {
        return static::where('status', true)
            ->where('compliance_level', '!=', self::LEVEL_BANNED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('name')
            ->toArray();
    }

    /** 敏感类目名集合（移民/签证/按摩等，发帖加强审核用） */
    public static function sensitiveNames(): array
    {
        return static::where('is_sensitive', true)->pluck('name')->toArray();
    }
}
