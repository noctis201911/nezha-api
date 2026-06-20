<?php

namespace App\Models;

use App\CentralLogics\Helpers;
use App\Traits\SerializesLocalDates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    // 聊天消息时间序列化为裸埃里温墙钟，避免前端 moment 按浏览器时区偏移（见 trait）。
    use HasFactory, SerializesLocalDates;

    protected $casts = [
        'conversation_id' => 'integer',
        'sender_id' => 'integer',
        'order_id' => 'integer',
        'is_seen' => 'integer'
    ];

    // order_summary: 顾客「一键发送订单卡片」用——消息引用的订单摘要（无引用则为 null）。
    protected $appends = ['file_full_url', 'order_summary'];

    public function sender()
    {
        return $this->belongsTo(UserInfo::class, 'sender_id');
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    // 消息引用的订单（顾客发卡片消息时携带）。带 OrderReference 取「取餐号」。
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id')->with('OrderReference');
    }

    // 订单卡片摘要：仅在 order_id 有值时构建；优先用已 eager-load 的关系避免 N+1。
    public function getOrderSummaryAttribute()
    {
        if (empty($this->order_id)) {
            return null;
        }
        $order = $this->relationLoaded('order') ? $this->getRelation('order') : Order::with('OrderReference')->find($this->order_id);
        if (!$order) {
            return null;
        }
        return [
            'id' => $order->id,
            'order_status' => $order->order_status,
            'order_type' => $order->order_type,
            'order_amount' => $order->order_amount,
            'token_number' => $order->OrderReference?->token_number,
            'created_at' => $order->created_at ? (string) $order->created_at : null,
        ];
    }

    public function getFileFullUrlAttribute(){
        $images = [];
        $value = is_array($this->file)
            ? $this->file
            : ($this->file && is_string($this->file) && $this->isValidJson($this->file)
                ? json_decode($this->file, true)
                : []);
        if ($value){
            foreach ($value as $item){
                $item = is_array($item)?$item:(is_object($item) && get_class($item) == 'stdClass' ? json_decode(json_encode($item), true):['img' => $item, 'storage' => 'public']);
                $images[] = Helpers::get_full_url('conversation',$item['img'],$item['storage']);
            }
        }

        return $images;
    }

    private function isValidJson($string)
    {
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }
}
