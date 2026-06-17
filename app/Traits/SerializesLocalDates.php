<?php

namespace App\Traits;

use Illuminate\Support\Carbon;

/**
 * 喂前端的模型 use 本 trait：把日期/时间戳序列化成「裸的埃里温墙钟」("Y-m-d H:i:s"，无 Z)。
 *
 * 背景（2026-06-17）：本服务器运行时 date_default_timezone=Asia/Yerevan（尽管 config
 * app.timezone=UTC），时间戳本就按埃里温本地墙钟存入 DB（now() 即埃里温）。但 Eloquent
 * 默认日期序列化会转成 UTC（埃里温 14:23 → 10:23Z），前端 moment 拿到带 Z 的值后又按
 * 「浏览器时区」换算，与其它裸串字段（如 Order.created_at / pending）不一致 → 非 +4 时区
 * （如国内测试机）看到偏移 4 小时；真实埃里温顾客（+4）本看一致，故 bug 仅在非 +4 暴露。
 *
 * 统一输出裸埃里温墙钟：前端 moment 原样显示、与浏览器时区无关，各处一致。
 * 起因=追踪页头部下单时间偏移(OrderDetail)；同类=聊天消息/会话时间(Message/Conversation)。
 * 以后任何「会被前端 moment 显示时间」的模型，直接 use 本 trait，勿再逐个补、防漏。
 */
trait SerializesLocalDates
{
    protected function serializeDate(\DateTimeInterface $date)
    {
        return Carbon::instance($date)->timezone('Asia/Yerevan')->format('Y-m-d H:i:s');
    }
}
