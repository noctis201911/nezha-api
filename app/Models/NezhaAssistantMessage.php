<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 商家助手会话消息（per-restaurant 持久层）。
 * role: user | ai。动作卡为 role=ai + action_type/payload/status 三态（pending→done|cancelled）。
 * 🔴 作用域一律 where('restaurant_id', 本店)，绝不接受外部传入店铺 id（防越权，见控制器）。
 */
class NezhaAssistantMessage extends Model
{
    protected $table = 'nezha_assistant_messages';

    protected $fillable = [
        'restaurant_id',
        'role',
        'content',
        'action_type',
        'payload',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
