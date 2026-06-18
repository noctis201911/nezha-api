<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活类目（后台可管理）。前端金刚区 / 发帖类目下拉 / 校验白名单均以本表为准。
 */
class LocalLifeCategory extends Model
{
    protected $fillable = [
        'name', 'emoji', 'color', 'tab', 'kind', 'sort_order', 'is_sensitive', 'status',
    ];

    protected $casts = [
        'is_sensitive' => 'boolean',
        'status'       => 'boolean',
        'sort_order'   => 'integer',
    ];

    /** 启用中的类目名数组（API 校验白名单 / 前端兜底用） */
    public static function activeNames(): array
    {
        return static::where('status', true)
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
