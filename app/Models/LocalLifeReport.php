<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 本地生活 UGC 帖举报记录。
 * status: 0待处理 1已处理(已下线) 2驳回
 */
class LocalLifeReport extends Model
{
    public const STATUS_PENDING  = 0; // 待处理
    public const STATUS_HANDLED  = 1; // 已处理(帖已下线)
    public const STATUS_REJECTED = 2; // 驳回(帖保留)

    protected $fillable = [
        'post_id',
        'user_id',
        'reason',
        'detail',
        'status',
    ];

    public function statusLabel(): string
    {
        return [
            self::STATUS_PENDING  => '待处理',
            self::STATUS_HANDLED  => '已处理',
            self::STATUS_REJECTED => '已驳回',
        ][$this->status] ?? '待处理';
    }

    public function post()
    {
        return $this->belongsTo(LocalLifePost::class, 'post_id');
    }
}
